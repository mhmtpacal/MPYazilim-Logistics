<?php

declare(strict_types=1);

namespace MPYazilim\Logistics\Builders;

use BadMethodCallException;
use InvalidArgumentException;
use MPYazilim\Logistics\Carriers\Ups\UpsCarrierAdapter;

final class UpsShipmentBuilder
{
    /** @var array<string,mixed> */
    private array $account = [];

    /** @var array<string,mixed> */
    private array $payload = [];

    private bool $testMode = false;

    public function __construct(
        private readonly UpsCarrierAdapter $adapter
    ) {
    }

    public function account(
        string $customerNumber,
        string $username,
        string $password
    ): self {
        foreach ([
            'customerNumber' => $customerNumber,
            'username' => $username,
            'password' => $password,
        ] as $field => $value) {
            $this->assertNotEmpty($value, $field);
        }

        $this->account = [
            'customer_number' => $customerNumber,
            'username' => $username,
            'password' => $password,
        ];

        return $this;
    }

    public function payload(
        string $shipperAccountNumber,
        string $shipperName,
        string $shipperAddress,
        int|string $shipperCityCode,
        int|string $shipperAreaCode,
        string $consigneeName,
        string $consigneeContactName,
        string $consigneeAddress,
        int|string $consigneeCityCode,
        int|string $consigneeAreaCode,
        string $consigneePhoneNumber,
        string $consigneeMobilePhoneNumber,
        string $packageType = 'D',
        int $serviceLevel = 3,
        int $paymentType = 2,
        int $idControlFlag = 0,
        int $phonePrealertFlag = 0,
        int $smsToShipper = 0,
        int $smsToConsignee = 1,
        int|float $insuranceValue = 0,
        string $insuranceValueCurrency = 'TL',
        int $numberOfPackages = 1,
        string $descriptionOfGoods = '0.5',
        int|float $length = 0.5,
        int|float $height = 0.5,
        int|float $width = 0.5,
        int|float $valueOfGoods = 0,
        string $valueOfGoodsCurrency = 'TL',
        int $valueOfGoodsPaymentType = 1,
        array $extra = []
    ): self {
        foreach ([
            'shipperAccountNumber' => $shipperAccountNumber,
            'shipperName' => $shipperName,
            'shipperAddress' => $shipperAddress,
            'consigneeName' => $consigneeName,
            'consigneeContactName' => $consigneeContactName,
            'consigneeAddress' => $consigneeAddress,
            'consigneePhoneNumber' => $consigneePhoneNumber,
            'consigneeMobilePhoneNumber' => $consigneeMobilePhoneNumber,
        ] as $field => $value) {
            $this->assertNotEmpty($value, $field);
        }

        $this->payload = [
            'ShipperAccountNumber' => $shipperAccountNumber,
            'ShipperName' => $shipperName,
            'ShipperAddress' => $shipperAddress,
            'ShipperCityCode' => $shipperCityCode,
            'ShipperAreaCode' => $shipperAreaCode,
            'ConsigneeName' => $consigneeName,
            'ConsigneeContactName' => $consigneeContactName,
            'ConsigneeAddress' => $consigneeAddress,
            'ConsigneeCityCode' => $consigneeCityCode,
            'ConsigneeAreaCode' => $consigneeAreaCode,
            'ConsigneePhoneNumber' => $consigneePhoneNumber,
            'ConsigneeMobilePhoneNumber' => $consigneeMobilePhoneNumber,
            'PackageType' => $packageType,
            'ServiceLevel' => $serviceLevel,
            'PaymentType' => $paymentType,
            'IdControlFlag' => $idControlFlag,
            'PhonePrealertFlag' => $phonePrealertFlag,
            'SmsToShipper' => $smsToShipper,
            'SmsToConsignee' => $smsToConsignee,
            'InsuranceValue' => $insuranceValue,
            'InsuranceValueCurrency' => $insuranceValueCurrency,
            'NumberOfPackages' => $numberOfPackages,
            'DescriptionOfGoods' => $descriptionOfGoods,
            'Length' => $length,
            'Height' => $height,
            'Width' => $width,
            'ValueOfGoods' => $valueOfGoods,
            'ValueOfGoodsCurrency' => $valueOfGoodsCurrency,
            'ValueOfGoodsPaymentType' => $valueOfGoodsPaymentType,
        ];

        if ($extra !== []) {
            $this->payload = array_merge($this->payload, $extra);
        }

        return $this;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function payloadRaw(array $payload): self
    {
        $this->payload = $payload;

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
    public function send(): array
    {
        $this->assertAccountConfigured();

        return $this->adapter->send($this->account, $this->payload, $this->testMode, false);
    }

    /**
     * @return array<string,mixed>
     */
    public function kargoTakip(string $trackingNo): array
    {
        $this->assertAccountConfigured();
        $this->assertNotEmpty($trackingNo, 'trackingNo');

        return $this->adapter->kargoTakip($this->account, $trackingNo);
    }

    /**
     * @return array<string,mixed>
     */
    public function iade(): array
    {
        throw new BadMethodCallException('UPS iade desteklemiyor');
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
        if (!isset($this->account['customer_number'], $this->account['username'], $this->account['password'])) {
            throw new InvalidArgumentException('Oncesinde account(...) cagrilmalidir');
        }
    }
}
