<?php

declare(strict_types=1);

namespace MPYazilim\Logistics\Builders;

use BadMethodCallException;
use InvalidArgumentException;
use MPYazilim\Logistics\Carriers\HepsiJet\HepsiJetCarrierAdapter;

final class HepsiJetShipmentBuilder
{
    /** @var array<string,mixed> */
    private array $account = [];

    /** @var array<string,mixed> */
    private array $payload = [];

    private bool $testMode = false;

    public function __construct(
        private readonly HepsiJetCarrierAdapter $adapter
    ) {
    }

    public function account(
        string $username,
        string $password,
        string $companyName,
        string $companyCode
    ): self {
        foreach ([
            'username' => $username,
            'password' => $password,
            'companyName' => $companyName,
            'companyCode' => $companyCode,
        ] as $field => $value) {
            $this->assertNotEmpty($value, $field);
        }

        $this->account = [
            'username' => $username,
            'password' => $password,
            'company_name' => $companyName,
            'company_code' => $companyCode,
        ];

        return $this;
    }

    public function payload(
        string $customerDeliveryNo,
        string $customerOrderId,
        string $totalParcels,
        string $desi,
        string $deliveryDateOriginal,
        string $deliveryType,
        string $productCode,
        string $receiverCompanyCustomerId,
        string $receiverFirstName,
        string $receiverLastName,
        string $receiverPhone1,
        string $receiverEmail,
        string $senderCompanyAddressId,
        string $senderCityName,
        string $senderTownName,
        string $senderDistrictName,
        string $senderAddressLine1,
        string $recipientCompanyAddressId,
        string $recipientCityName,
        string $recipientTownName,
        string $recipientDistrictName,
        string $recipientAddressLine1,
        string $recipientPerson,
        string $recipientPersonPhone1,
        string $countryName = 'Turkiye',
        string $deliverySlotOriginal = '0',
        array $extra = []
    ): self {
        foreach ([
            'customerDeliveryNo' => $customerDeliveryNo,
            'customerOrderId' => $customerOrderId,
            'deliveryDateOriginal' => $deliveryDateOriginal,
            'deliveryType' => $deliveryType,
            'productCode' => $productCode,
            'receiverFirstName' => $receiverFirstName,
            'receiverLastName' => $receiverLastName,
            'receiverPhone1' => $receiverPhone1,
            'senderCompanyAddressId' => $senderCompanyAddressId,
            'senderCityName' => $senderCityName,
            'senderTownName' => $senderTownName,
            'senderAddressLine1' => $senderAddressLine1,
            'recipientCompanyAddressId' => $recipientCompanyAddressId,
            'recipientCityName' => $recipientCityName,
            'recipientTownName' => $recipientTownName,
            'recipientAddressLine1' => $recipientAddressLine1,
            'recipientPerson' => $recipientPerson,
            'recipientPersonPhone1' => $recipientPersonPhone1,
        ] as $field => $value) {
            $this->assertNotEmpty($value, $field);
        }

        $this->payload = [
            'delivery' => [
                'customerDeliveryNo' => $customerDeliveryNo,
                'customerOrderId' => $customerOrderId,
                'totalParcels' => $totalParcels,
                'desi' => $desi,
                'deliverySlotOriginal' => $deliverySlotOriginal,
                'deliveryDateOriginal' => $deliveryDateOriginal,
                'deliveryType' => $deliveryType,
                'product' => [
                    'productCode' => $productCode,
                ],
                'receiver' => [
                    'companyCustomerId' => $receiverCompanyCustomerId,
                    'firstName' => $receiverFirstName,
                    'lastName' => $receiverLastName,
                    'phone1' => $receiverPhone1,
                    'email' => $receiverEmail,
                ],
                'senderAddress' => [
                    'companyAddressId' => $senderCompanyAddressId,
                    'country' => ['name' => $countryName],
                    'city' => ['name' => $senderCityName],
                    'town' => ['name' => $senderTownName],
                    'district' => ['name' => $senderDistrictName],
                    'addressLine1' => $senderAddressLine1,
                ],
                'recipientAddress' => [
                    'companyAddressId' => $recipientCompanyAddressId,
                    'country' => ['name' => $countryName],
                    'city' => ['name' => $recipientCityName],
                    'town' => ['name' => $recipientTownName],
                    'district' => ['name' => $recipientDistrictName],
                    'addressLine1' => $recipientAddressLine1,
                ],
                'recipientPerson' => $recipientPerson,
                'recipientPersonPhone1' => $recipientPersonPhone1,
            ],
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
    public function kargoTakip(string $barcode): array
    {
        $this->assertAccountConfigured();
        $this->assertNotEmpty($barcode, 'barcode');

        return $this->adapter->kargoTakip($this->account, $barcode, $this->testMode);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function takipLinkOlustur(array $data): array
    {
        $this->assertAccountConfigured();

        return $this->adapter->takipLinkOlustur($this->account, $data, $this->testMode);
    }

    /**
     * @return array<string,mixed>
     */
    public function kargoSil(string $barcode): array
    {
        $this->assertAccountConfigured();
        $this->assertNotEmpty($barcode, 'barcode');

        return $this->adapter->kargoSil($this->account, $barcode, $this->testMode);
    }

    /**
     * @param array<int,mixed> $arguments
     * @return array<string,mixed>
     */
    public function __call(string $name, array $arguments): array
    {
        if ($name === 'return') {
            throw new BadMethodCallException('HepsiJet iade desteklemiyor');
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
        if (!isset($this->account['username'], $this->account['password'], $this->account['company_name'], $this->account['company_code'])) {
            throw new InvalidArgumentException('Oncesinde account(...) cagrilmalidir');
        }
    }
}

