<?php

declare(strict_types=1);

namespace Liberu\Shipping\ShipEngine;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Liberu\Shipping\Contracts\Contracts\AddressValidationContract;
use Liberu\Shipping\Contracts\Contracts\RateQuotingContract;
use Liberu\Shipping\Contracts\Contracts\ShipmentCreationContract;
use Liberu\Shipping\Contracts\Contracts\ShippingProviderAdapterContract;
use Liberu\Shipping\Contracts\Contracts\ShippingWebhookVerifierContract;
use Liberu\Shipping\Contracts\Contracts\TrackingContract;
use Liberu\Shipping\Contracts\DTOs\AddressDto;
use Liberu\Shipping\Contracts\DTOs\AddressValidationResultDto;
use Liberu\Shipping\Contracts\DTOs\CancelShipmentCommand;
use Liberu\Shipping\Contracts\DTOs\CancelShipmentResultDto;
use Liberu\Shipping\Contracts\DTOs\ConnectionHealthDto;
use Liberu\Shipping\Contracts\DTOs\CreateShipmentCommand;
use Liberu\Shipping\Contracts\DTOs\GetRatesCommand;
use Liberu\Shipping\Contracts\DTOs\GetTrackingCommand;
use Liberu\Shipping\Contracts\DTOs\MoneyAmount;
use Liberu\Shipping\Contracts\DTOs\ParcelDto;
use Liberu\Shipping\Contracts\DTOs\RawWebhookPayloadDto;
use Liberu\Shipping\Contracts\DTOs\ShipmentResultDto;
use Liberu\Shipping\Contracts\DTOs\ShippingAccountContextDto;
use Liberu\Shipping\Contracts\DTOs\ShippingCapabilityDescriptorDto;
use Liberu\Shipping\Contracts\DTOs\ShippingRateDto;
use Liberu\Shipping\Contracts\DTOs\ShippingWebhookEventDto;
use Liberu\Shipping\Contracts\DTOs\TrackingEventDto;
use Liberu\Shipping\Contracts\DTOs\TrackingResultDto;
use Liberu\Shipping\Contracts\DTOs\ValidateAddressCommand;
use Liberu\Shipping\Contracts\Enums\ShipmentLifecycleState;
use RuntimeException;

final readonly class ShipEngineShippingAdapter implements AddressValidationContract, RateQuotingContract, ShipmentCreationContract, ShippingProviderAdapterContract, ShippingWebhookVerifierContract, TrackingContract
{
    private const BASE_URL = 'https://api.shipengine.com/v1';

    public function providerKey(): string
    {
        return 'shipengine';
    }

    public function descriptor(): ShippingCapabilityDescriptorDto
    {
        return new ShippingCapabilityDescriptorDto(
            providerKey: 'shipengine',
            displayName: 'ShipEngine',
            supportedCarriers: ['USPS', 'UPS', 'FedEx', 'DHL', 'Stamps.com', 'Royal Mail', 'Canada Post'],
            supportedCountries: ['US', 'GB', 'CA', 'EU', 'AU'],
            isAggregator: true,
            supportsRateQuoting: true,
            supportsLabelPurchase: true,
            supportsTracking: true,
            supportsAddressValidation: true,
            supportsWebhooks: true,
            supportsPickupScheduling: false,
        );
    }

    public function testConnection(ShippingAccountContextDto $account): ConnectionHealthDto
    {
        if ($this->apiKey($account) === '') {
            return new ConnectionHealthDto('shipengine', false, 'ShipEngine API key is missing.');
        }

        $response = $this->client($account)->get(self::BASE_URL.'/carriers');

        return new ConnectionHealthDto(
            providerKey: 'shipengine',
            healthy: $response->successful(),
            message: $response->successful() ? 'ShipEngine API connection verified.' : 'ShipEngine API rejected credentials.',
            details: ['status' => $response->status(), 'sandbox' => $account->sandbox],
        );
    }

    public function getRates(GetRatesCommand $command): array
    {
        if ($this->apiKey($command->account) === '') {
            return [];
        }

        $carrierIds = (array) ($command->account->credentials['carrier_ids'] ?? []);

        $response = $this->client($command->account)->post(self::BASE_URL.'/rates', [
            'rate_options' => [
                'carrier_ids' => $carrierIds,
            ],
            'shipment' => [
                'ship_to' => $this->formatAddress($command->to),
                'ship_from' => $this->formatAddress($command->from),
                'packages' => [$this->formatPackage($command->parcel)],
            ],
        ]);

        if (! $response->successful()) {
            return [];
        }

        $shipmentId = is_string($response->json('shipment_id')) ? $response->json('shipment_id') : null;
        $rawRates = $response->json('rate_response.rates', []);
        if (! is_array($rawRates)) {
            return [];
        }

        $mapped = [];
        foreach ($rawRates as $rate) {
            if (! is_array($rate) || ! isset($rate['shipping_amount']['amount'])) {
                continue;
            }
            $carrier = (string) ($rate['carrier_friendly_name'] ?? $rate['carrier_code'] ?? 'Unknown');
            if ($command->carrierFilter !== null && strcasecmp($carrier, $command->carrierFilter) !== 0) {
                continue;
            }

            $mapped[] = new ShippingRateDto(
                providerKey: 'shipengine',
                carrier: $carrier,
                service: (string) ($rate['service_type'] ?? $rate['service_code'] ?? 'Standard'),
                amount: MoneyAmount::fromMajor(
                    $rate['shipping_amount']['amount'],
                    (string) ($rate['shipping_amount']['currency'] ?? 'USD'),
                ),
                deliveryDays: isset($rate['delivery_days']) ? (int) $rate['delivery_days'] : null,
                rateId: isset($rate['rate_id']) ? (string) $rate['rate_id'] : null,
                shipmentId: $shipmentId,
                estimatedDeliveryDate: isset($rate['estimated_delivery_date']) ? (string) $rate['estimated_delivery_date'] : null,
            );
        }

        return $mapped;
    }

    public function createShipment(CreateShipmentCommand $command): ShipmentResultDto
    {
        $url = $command->rateId !== null
            ? self::BASE_URL."/labels/rates/{$command->rateId}"
            : self::BASE_URL.'/labels';

        $payload = $command->rateId !== null
            ? ['label_format' => strtolower($command->labelFormat)]
            : [
                'shipment' => [
                    'service_code' => $command->service,
                    'ship_to' => $this->formatAddress($command->to),
                    'ship_from' => $this->formatAddress($command->from),
                    'packages' => [$this->formatPackage($command->parcel)],
                ],
                'label_format' => strtolower($command->labelFormat),
            ];

        $response = $this->client($command->account)->post($url, $payload);

        if (! $response->successful()) {
            throw new RuntimeException('ShipEngine label creation failed: '.$response->status());
        }

        $data = $response->json();
        $cost = is_array($data['shipment_cost'] ?? null) ? $data['shipment_cost'] : null;

        return new ShipmentResultDto(
            providerKey: 'shipengine',
            providerShipmentId: (string) ($data['label_id'] ?? $command->idempotencyKey),
            state: ShipmentLifecycleState::LabelPurchased,
            carrier: isset($data['carrier_code']) ? (string) $data['carrier_code'] : $command->carrier,
            service: isset($data['service_code']) ? (string) $data['service_code'] : $command->service,
            trackingNumber: isset($data['tracking_number']) ? (string) $data['tracking_number'] : null,
            labelUrl: isset($data['label_download']['pdf'])
                ? (string) $data['label_download']['pdf']
                : (isset($data['label_download']['href']) ? (string) $data['label_download']['href'] : null),
            labelFormat: $command->labelFormat,
            amount: ($cost !== null && isset($cost['amount']))
                ? MoneyAmount::fromMajor($cost['amount'], (string) ($cost['currency'] ?? 'USD'))
                : null,
        );
    }

    public function cancelShipment(CancelShipmentCommand $command): CancelShipmentResultDto
    {
        $response = $this->client($command->account)->put(
            self::BASE_URL."/labels/{$command->providerShipmentId}/void"
        );

        if (! $response->successful()) {
            throw new RuntimeException('ShipEngine label void failed: '.$response->status());
        }

        $approved = (bool) ($response->json('approved') ?? false);

        return new CancelShipmentResultDto(
            providerKey: 'shipengine',
            providerShipmentId: $command->providerShipmentId,
            state: $approved ? ShipmentLifecycleState::Cancelled : ShipmentLifecycleState::Failed,
            refunded: $approved,
            message: isset($response->json()['message']) ? (string) $response->json('message') : null,
        );
    }

    public function getTracking(GetTrackingCommand $command): TrackingResultDto
    {
        $response = $this->client($command->account)->get(self::BASE_URL.'/tracking', array_filter([
            'carrier_code' => $command->carrier !== null ? strtolower($command->carrier) : null,
            'tracking_number' => $command->trackingNumber,
        ], static fn ($v): bool => $v !== null && $v !== ''));

        if (! $response->successful()) {
            throw new RuntimeException('ShipEngine tracking lookup failed: '.$response->status());
        }

        $data = $response->json();
        $events = [];
        foreach ((array) ($data['events'] ?? []) as $event) {
            if (! is_array($event)) {
                continue;
            }
            $location = trim(implode(', ', array_filter([
                $event['city_locality'] ?? null,
                $event['state_province'] ?? null,
                $event['country_code'] ?? null,
            ])));
            $events[] = new TrackingEventDto(
                description: (string) ($event['description'] ?? ''),
                state: $this->mapStatus((string) ($event['status_code'] ?? $data['status_code'] ?? 'UN')),
                occurredAtIso8601: isset($event['occurred_at']) ? (string) $event['occurred_at'] : null,
                location: $location !== '' ? $location : null,
                rawStatus: isset($event['carrier_status_code']) ? (string) $event['carrier_status_code'] : null,
            );
        }

        return new TrackingResultDto(
            providerKey: 'shipengine',
            trackingNumber: (string) ($data['tracking_number'] ?? $command->trackingNumber),
            state: $this->mapStatus((string) ($data['status_code'] ?? 'UN')),
            carrier: isset($data['carrier_code']) ? (string) $data['carrier_code'] : $command->carrier,
            estimatedDeliveryIso8601: isset($data['estimated_delivery_date']) ? (string) $data['estimated_delivery_date'] : null,
            events: $events,
        );
    }

    public function validateAddress(ValidateAddressCommand $command): AddressValidationResultDto
    {
        $response = $this->client($command->account)->post(self::BASE_URL.'/addresses/validate', [
            $this->formatAddress($command->address),
        ]);

        if (! $response->successful()) {
            return new AddressValidationResultDto('shipengine', false, null, ['ShipEngine address validation failed.']);
        }

        $first = is_array($response->json('0')) ? $response->json('0') : [];
        $status = strtolower((string) ($first['status'] ?? 'error'));
        $matched = is_array($first['matched_address'] ?? null) ? $first['matched_address'] : null;
        $messages = [];
        foreach ((array) ($first['messages'] ?? []) as $msg) {
            if (is_array($msg) && isset($msg['message'])) {
                $messages[] = (string) $msg['message'];
            }
        }

        return new AddressValidationResultDto(
            providerKey: 'shipengine',
            valid: $status === 'verified',
            normalizedAddress: $matched !== null ? AddressDto::fromArray([
                'name' => $matched['name'] ?? $command->address->name,
                'street1' => $matched['address_line1'] ?? $command->address->street1,
                'street2' => $matched['address_line2'] ?? $command->address->street2,
                'city' => $matched['city_locality'] ?? $command->address->city,
                'state' => $matched['state_province'] ?? $command->address->state,
                'postal_code' => $matched['postal_code'] ?? $command->address->postalCode,
                'country' => $matched['country_code'] ?? $command->address->country,
            ]) : null,
            messages: $messages,
        );
    }

    public function verifyAndParse(RawWebhookPayloadDto $payload): ShippingWebhookEventDto
    {
        if ($payload->signingSecret !== '') {
            $sig = $payload->headers['x-shipengine-signature'] ?? $payload->headers['X-ShipEngine-Signature'] ?? '';
            if (is_array($sig)) {
                $sig = $sig[0] ?? '';
            }
            $expected = hash_hmac('sha256', $payload->rawBody, $payload->signingSecret);
            if (! hash_equals($expected, (string) $sig)) {
                throw new RuntimeException('Invalid ShipEngine webhook signature.');
            }
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($payload->rawBody, true) ?? [];
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $statusCode = isset($data['status_code']) ? (string) $data['status_code'] : null;

        return new ShippingWebhookEventDto(
            providerKey: 'shipengine',
            providerEventId: (string) ($decoded['resource_url'] ?? hash('sha256', $payload->rawBody)),
            eventType: (string) ($decoded['resource_type'] ?? 'unknown'),
            providerReference: isset($data['label_id']) ? (string) $data['label_id'] : null,
            trackingNumber: isset($data['tracking_number']) ? (string) $data['tracking_number'] : null,
            targetState: $statusCode !== null ? $this->mapStatus($statusCode) : null,
            payload: $decoded,
        );
    }

    private function client(ShippingAccountContextDto $account): PendingRequest
    {
        return Http::withHeaders([
            'API-Key' => $this->apiKey($account),
        ])->timeout($account->timeoutSeconds)->acceptJson();
    }

    private function apiKey(ShippingAccountContextDto $account): string
    {
        return (string) ($account->credentials['api_key'] ?? '');
    }

    /** @return array<string, mixed> */
    private function formatAddress(AddressDto $address): array
    {
        return array_filter([
            'name' => $address->name,
            'company_name' => $address->company,
            'address_line1' => $address->street1,
            'address_line2' => $address->street2,
            'city_locality' => $address->city,
            'state_province' => $address->state,
            'postal_code' => $address->postalCode,
            'country_code' => $address->country,
            'phone' => $address->phone,
        ], static fn ($v): bool => $v !== null);
    }

    /** @return array<string, mixed> */
    private function formatPackage(ParcelDto $parcel): array
    {
        $pkg = [
            'weight' => [
                'value' => $parcel->weightInOunces(),
                'unit' => 'ounce',
            ],
        ];
        if ($parcel->length !== null && $parcel->width !== null && $parcel->height !== null) {
            $pkg['dimensions'] = [
                'unit' => $parcel->dimensionUnit === 'cm' ? 'centimeter' : 'inch',
                'length' => $parcel->length,
                'width' => $parcel->width,
                'height' => $parcel->height,
            ];
        }

        return $pkg;
    }

    private function mapStatus(string $code): ShipmentLifecycleState
    {
        return match (strtoupper($code)) {
            'AC', 'NY' => ShipmentLifecycleState::PreTransit,
            'IT' => ShipmentLifecycleState::InTransit,
            'DE' => ShipmentLifecycleState::Delivered,
            'EX', 'AT' => ShipmentLifecycleState::Exception,
            default => ShipmentLifecycleState::Unknown,
        };
    }
}
