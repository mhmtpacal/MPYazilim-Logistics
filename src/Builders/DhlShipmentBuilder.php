<?php

declare(strict_types=1);

namespace MPYazilim\Logistics\Builders;

use BadMethodCallException;
use InvalidArgumentException;
use MPYazilim\Logistics\Carriers\Dhl\DhlCarrierAdapter;

final class DhlShipmentBuilder
{
    /** @var array<string,mixed> */
    private array $account = [];

    /** @var array<string,mixed> */
    private array $payload = [];

    private bool $testMode = false;

    public function __construct(
        private readonly DhlCarrierAdapter $adapter
    ) {
    }

    public function account(
        string $username,
        string $password,
        string $clientId,
        string $clientSecret
    ): self {
        foreach ([
            'username' => $username,
            'password' => $password,
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
        ] as $field => $value) {
            $this->assertNotEmpty($value, $field);
        }

        $this->account = [
            'username' => $username,
            'password' => $password,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ];

        return $this;
    }

    public function payload(
        string $referenceId,
        string $barcode,
        bool $isCOD,
        int|float $codAmount,
        int $shipmentServiceType,
        int $packagingType,
        int $paymentType,
        int $deliveryType,
        string $content,
        string $description,
        int $cityCode,
        string $cityName,
        string $districtName,
        int $districtCode,
        string $address,
        string $fullName,
        string $mobilePhoneNumber,
        string $pieceBarcode = 'Product',
        int|float $pieceDesi = 0,
        int|float $pieceKg = 0,
        string $pieceContent = '',
        int $smsPreference1 = 0,
        int $smsPreference2 = 0,
        int $smsPreference3 = 0,
        string $billOfLandingId = '',
        string $marketPlaceShortCode = '',
        string $marketPlaceSaleCode = '',
        string $pudoId = '',
        string $customerId = '',
        string $refCustomerId = '',
        string $bussinessPhoneNumber = '',
        string $email = '',
        string $taxOffice = '',
        string $taxNumber = '',
        string $homePhoneNumber = '',
        ?array $shipper = null
    ): self {
        foreach ([
            'referenceId' => $referenceId,
            'barcode' => $barcode,
            'content' => $content,
            'description' => $description,
            'cityName' => $cityName,
            'districtName' => $districtName,
            'address' => $address,
            'fullName' => $fullName,
            'mobilePhoneNumber' => $mobilePhoneNumber,
        ] as $field => $value) {
            $this->assertNotEmpty($value, $field);
        }

        $shipmentBarcode = $billOfLandingId !== '' ? $billOfLandingId : $barcode;

        $this->payload = [
            'order' => [
                'referenceId' => $referenceId,
                'barcode' => $barcode,
                'billOfLandingId' => $shipmentBarcode,
                'isCOD' => $isCOD ? 1 : 0,
                'codAmount' => $isCOD ? $codAmount : 0,
                'shipmentServiceType' => $shipmentServiceType,
                'packagingType' => $packagingType,
                'smsPreference1' => $smsPreference1,
                'smsPreference2' => $smsPreference2,
                'smsPreference3' => $smsPreference3,
                'paymentType' => $paymentType,
                'deliveryType' => $deliveryType,
                'content' => $content,
                'description' => $description,
                'marketPlaceShortCode' => $marketPlaceShortCode,
                'marketPlaceSaleCode' => $marketPlaceSaleCode,
                'pudoId' => $pudoId,
            ],
            'orderPieceList' => [[
                'barcode' => $pieceBarcode,
                'desi' => $pieceDesi,
                'kg' => $pieceKg,
                'content' => $pieceContent,
            ]],
            'recipient' => [
                'customerId' => $customerId,
                'refCustomerId' => $refCustomerId,
                'cityCode' => $cityCode,
                'cityName' => $cityName,
                'districtName' => $districtName,
                'districtCode' => $districtCode,
                'address' => $address,
                'bussinessPhoneNumber' => $bussinessPhoneNumber,
                'email' => $email,
                'taxOffice' => $taxOffice,
                'taxNumber' => $taxNumber,
                'fullName' => $fullName,
                'homePhoneNumber' => $homePhoneNumber,
                'mobilePhoneNumber' => $mobilePhoneNumber,
            ],
        ];

        if (is_array($shipper) && $shipper !== []) {
            $this->payload['shipper'] = $shipper;
        }

        return $this;
    }

    public function test(bool $enabled = true): self
    {
        $this->testMode = $enabled;

        return $this;
    }

    /**
     * @return array<string,mixed>
     */
    public function kargoHareketleri(string $trackingNo): array
    {
        $this->assertAccountConfigured();
        $this->assertNotEmpty($trackingNo, 'trackingNo');

        return $this->adapter->kargoHareketleri($this->account, $trackingNo, $this->testMode);
    }

    /**
     * @return array<string,mixed>
     */
    public function kargoDurum(string $trackingNo): array
    {
        $this->assertAccountConfigured();
        $this->assertNotEmpty($trackingNo, 'trackingNo');

        return $this->adapter->kargoDurum($this->account, $trackingNo, $this->testMode);
    }

    /**
     * @return array<string,mixed>
     */
    public function send(): array
    {
        return $this->adapter->send($this->account, $this->payload, $this->testMode, false);
    }

    /**
     * @return array<string,mixed>
     */
    public function iade(): array
    {
        return $this->adapter->send($this->account, $this->payload, $this->testMode, true);
    }

    /**
     * @param array<int,mixed> $arguments
     * @return array<string,mixed>
     */
    public function __call(string $name, array $arguments): array
    {
        if ($name === 'return') {
            return $this->iade();
        }

        throw new BadMethodCallException(sprintf('Undefined method: %s::%s()', self::class, $name));
    }

    private function assertNotEmpty(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('%s bos birakilamaz', $field));
        }
    }

    private function assertAccountConfigured(): void
    {
        if (!isset($this->account['username'], $this->account['password'], $this->account['client_id'], $this->account['client_secret'])) {
            throw new InvalidArgumentException('Oncesinde account(...) cagrilmalidir');
        }
    }
}
