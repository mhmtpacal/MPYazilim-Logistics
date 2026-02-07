<?php

declare(strict_types=1);

namespace MPYazilim\Logistics\Builders;

use BadMethodCallException;
use InvalidArgumentException;
use MPYazilim\Logistics\Contracts\CarrierAdapterInterface;

final class PttShipmentBuilder
{
    /** @var array<string,mixed> */
    private array $account = [];

    /** @var array<string,mixed> */
    private array $payload = [];

    private bool $testMode = false;

    public function __construct(
        private readonly CarrierAdapterInterface $adapter
    ) {
    }

    public function account(
        string $username,
        string $password,
        string $postaCeki
    ): self {
        $this->assertNotEmpty($username, 'username');
        $this->assertNotEmpty($password, 'password');
        $this->assertNotEmpty($postaCeki, 'postaCeki');

        $this->account = [
            'username' => $username,
            'password' => $password,
            'posta_ceki' => $postaCeki,
        ];

        return $this;
    }

    /**
     * PTT kabulEkle2 payload (tek kayit).
     */
    public function payload(
        string $dosyaAdi,
        string $gonderiTur,
        string $gonderiTip,
        string $aAdres,
        string $aliciAdi,
        int|float $agirlik,
        string $aliciIlAdi,
        string $aliciIlceAdi,
        string $aliciSms,
        string $barkodNo,
        int|float|string $odemeSartUcreti,
        int|string $musteriReferansNo,
        string $rezerve1,
        int|float $yukseklik,
        string $boy,
        string $desi,
        string $ekhizmet,
        string $en,
        string $odemesekli,
        ?array $gondericibilgi = null,
        string $kullanici = 'PttWs'
    ): self {
        foreach ([
            'dosyaAdi' => $dosyaAdi,
            'gonderiTur' => $gonderiTur,
            'gonderiTip' => $gonderiTip,
            'aAdres' => $aAdres,
            'aliciAdi' => $aliciAdi,
            'aliciIlAdi' => $aliciIlAdi,
            'aliciIlceAdi' => $aliciIlceAdi,
            'aliciSms' => $aliciSms,
            'rezerve1' => $rezerve1,
            'odemesekli' => $odemesekli,
        ] as $field => $value) {
            $this->assertNotEmpty($value, $field);
        }

        $record = [
            'kullanici' => $kullanici,
            'dosyaAdi' => $dosyaAdi,
            'gonderiTur' => $gonderiTur,
            'gonderiTip' => $gonderiTip,
            'aAdres' => $aAdres,
            'aliciAdi' => $aliciAdi,
            'agirlik' => $agirlik,
            'aliciIlAdi' => $aliciIlAdi,
            'aliciIlceAdi' => $aliciIlceAdi,
            'aliciSms' => $aliciSms,
            'barkodNo' => $barkodNo,
            'odeme_sart_ucreti' => $odemeSartUcreti,
            'musteriReferansNo' => (string) $musteriReferansNo,
            'rezerve1' => $rezerve1,
            'yukseklik' => $yukseklik,
            'boy' => $boy,
            'desi' => $desi,
            'ekhizmet' => $ekhizmet,
            'en' => $en,
            'odemesekli' => $odemesekli,
        ];

        if (is_array($gondericibilgi) && $gondericibilgi !== []) {
            $record['gondericibilgi'] = $gondericibilgi;
        }

        $this->payload = [
            'kullanici' => $kullanici,
            'dosyaAdi' => $dosyaAdi,
            'gonderiTip' => $gonderiTip,
            'gonderiTur' => $gonderiTur,
            'dongu' => [$record],
        ];

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
}
