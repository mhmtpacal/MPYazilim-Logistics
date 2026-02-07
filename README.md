# MPYazilim-Logistics
Coklu kargo API kutuphanesi.

## Kurulum

```bash
composer require mpyazilim/logistics
```

## Ilk Entegrasyon: PTT

```php
<?php

use MPYazilim\Logistics\MPLogistics;

$response = MPLogistics::ptt()
    ->account(
        username: 'NORMAL_KULLANICI',
        password: 'NORMAL_SIFRE',
        postaCeki: 'POSTA_CEKI'
    )
    ->payload(
        dosyaAdi: 'BATCH_20260207',
        gonderiTur: 'KARGO',
        gonderiTip: 'NORMAL',
        aAdres: 'Mahalle Adres Ilce/Il',
        aliciAdi: 'Ad Soyad',
        agirlik: 0,
        aliciIlAdi: 'ISTANBUL',
        aliciIlceAdi: 'KADIKOY',
        aliciSms: '905551112233',
        barkodNo: '1234567890',
        odemeSartUcreti: 100.50,
        musteriReferansNo: 1452,
        rezerve1: 'POSTA_CEKI',
        yukseklik: 0,
        boy: '',
        desi: '',
        ekhizmet: '',
        en: '',
        odemesekli: 'GONDERICI',
        gondericibilgi: null,
        kullanici: 'PttWs'
    )
    ->test(false)
    ->send();
```

Iade gonderimi:

```php
$response = MPLogistics::ptt()
    ->account(
        username: 'IADE_KULLANICI_ADI', // iadeKullaniciAdi
        password: 'IADE_SIFRE', // iadeSifre
        postaCeki: 'POSTA_CEKI'
    )
    ->payload(
        dosyaAdi: 'BATCH_RETURN_20260207',
        gonderiTur: 'KARGO',
        gonderiTip: 'NORMAL',
        aAdres: 'Mahalle Adres Ilce/Il',
        aliciAdi: 'Ad Soyad',
        agirlik: 0,
        aliciIlAdi: 'ISTANBUL',
        aliciIlceAdi: 'KADIKOY',
        aliciSms: '905551112233',
        barkodNo: '',
        odemeSartUcreti: 0,
        musteriReferansNo: 1452, // refno
        rezerve1: 'POSTA_CEKI',
        yukseklik: 0,
        boy: '',
        desi: '',
        ekhizmet: '',
        en: '',
        odemesekli: 'MH',
        gondericibilgi: [
            'gonderici_adi' => 'Ad',
            'gonderici_adresi' => 'Mahalle Adres Ilce/Il',
            'gonderici_email' => '',
            'gonderici_il_ad' => 'ISTANBUL',
            'gonderici_ilce_ad' => 'KADIKOY',
            'gonderici_posta_kodu' => '',
            'gonderici_sms' => '',
            'gonderici_soyadi' => 'Soyad',
            'gonderici_telefonu' => '905551112233',
            'gonderici_ulke_id' => '',
        ]
    )
    ->test(false)
    ->return();
```

## Notlar

- PTT icin dizi anahtari yerine named argument kullanilir; IDE otomatik alan onerir.
- `account()` icinde `username`, `password`, `postaCeki` bos gecilemez.
- `payload()` parametreleri PTT veri modeline ozel olarak tanimlidir.
- `->return()` icin ayrica bir alan yok; iade bilgilerini dogrudan `account(username:, password:)` icine girersin.
- `->return()` cagrildiginda `musteriId = username`, `->send()` cagrildiginda `musteriId = postaCeki` olarak gonderilir.
- PTT test ortami icin `->test(true)` kullanilir ve otomatik olarak `...V2Test` / `...YuklemeTest` endpointlerine gider.
