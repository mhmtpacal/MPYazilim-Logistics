<?php

declare(strict_types=1);

namespace MPYazilim\Logistics\Builders;

use BadMethodCallException;
use InvalidArgumentException;
use MPYazilim\Logistics\Carriers\Aras\ArasCarrierAdapter;

final class ArasShipmentBuilder
{
    /** @var array<string,mixed> */
    private array $account = [];

    /** @var array<string,mixed> */
    private array $payload = [];

    private bool $testMode = false;

    public function __construct(
        private readonly ArasCarrierAdapter $adapter
    ) {
    }

    public function account(
        string $username,
        string $password,
        string $customerCode
    ): self {
        foreach ([
            'username' => $username,
            'password' => $password,
            'customerCode' => $customerCode,
        ] as $field => $value) {
            $this->assertNotEmpty($value, $field);
        }

        $this->account = [
            'username' => $username,
            'password' => $password,
            'customer_code' => $customerCode,
        ];

        return $this;
    }

    public function payload(
        string $tradingWaybillNumber,
        string|int $integrationCode,
        string $receiverName,
        string $receiverAddress,
        string $receiverPhone1,
        string $receiverCityName,
        string $receiverTownName,
        int $payorTypeCode = 1,
        int $isWorldWide = 0,
        int $isCod = 0,
        int|float $codAmount = 0,
        int $codCollectionType = 0,
        string $barcodeNumber = ''
    ): self {
        foreach ([
            'tradingWaybillNumber' => $tradingWaybillNumber,
            'integrationCode' => (string) $integrationCode,
            'receiverName' => $receiverName,
            'receiverAddress' => $receiverAddress,
            'receiverPhone1' => $receiverPhone1,
            'receiverCityName' => $receiverCityName,
            'receiverTownName' => $receiverTownName,
        ] as $field => $value) {
            $this->assertNotEmpty($value, $field);
        }

        $this->payload = [
            'TradingWaybillNumber' => $tradingWaybillNumber,
            'IntegrationCode' => (string) $integrationCode,
            'ReceiverName' => $receiverName,
            'ReceiverAddress' => $receiverAddress,
            'ReceiverPhone1' => $receiverPhone1,
            'ReceiverCityName' => $receiverCityName,
            'ReceiverTownName' => $receiverTownName,
            'PayorTypeCode' => $payorTypeCode,
            'IsWorldWide' => $isWorldWide,
            'IsCod' => $isCod,
            'CodAmount' => $codAmount,
            'CodCollectionType' => $codCollectionType,
            'PieceDetails' => ['BarcodeNumber' => $barcodeNumber],
        ];

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
    public function barkodSil(string $integrationCode): array
    {
        $this->assertAccountConfigured();
        $this->assertNotEmpty($integrationCode, 'integrationCode');

        return $this->adapter->barkodSil($this->account, $integrationCode);
    }

    /**
     * @return array<string,mixed>
     */
    public function iade(): array
    {
        return $this->send();
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
        if (!isset($this->account['username'], $this->account['password'], $this->account['customer_code'])) {
            throw new InvalidArgumentException('Oncesinde account(...) cagrilmalidir');
        }
    }
}
