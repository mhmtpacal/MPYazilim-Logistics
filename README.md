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

$hareketler = MPLogistics::ptt()
    ->account(
        username: 'NORMAL_KULLANICI',
        password: 'NORMAL_SIFRE',
        postaCeki: 'POSTA_CEKI'
    )
    ->test(false)
    ->kargoHareketleri('1234567890');

$referansTakip = MPLogistics::ptt()
    ->account(
        username: 'NORMAL_KULLANICI',
        password: 'NORMAL_SIFRE',
        postaCeki: 'POSTA_CEKI'
    )
    ->test(false)
    ->referansTakip('REF-1001');
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


---

## DHL (MNG) Entegrasyonu

Normal gonderi:

```php
<?php

use MPYazilim\Logistics\MPLogistics;

$response = MPLogistics::dhl()
    ->account(
        username: 'MNG_CUSTOMER_NUMBER',
        password: 'MNG_PASSWORD',
        clientId: 'MNG_CLIENT_ID',
        clientSecret: 'MNG_CLIENT_SECRET'
    )
    ->payload(
        referenceId: 'REF-1001',
        barcode: 'REF-1001',
        isCOD: false,
        codAmount: 0,
        shipmentServiceType: 1,
        packagingType: 1,
        paymentType: 1,
        deliveryType: 1,
        content: '1001',
        description: 'Order : 1001',
        cityCode: 34,
        cityName: 'ISTANBUL',
        districtName: 'KADIKOY',
        districtCode: 34001,
        address: 'Mahalle Sokak No:1',
        fullName: 'Ad Soyad',
        mobilePhoneNumber: '905551112233',
        pieceBarcode: 'Product',
        pieceDesi: 0,
        pieceKg: 0,
        pieceContent: '',
        smsPreference1: 0,
        smsPreference2: 0,
        smsPreference3: 0,
        billOfLandingId: 'REF-1001',
        marketPlaceShortCode: '',
        marketPlaceSaleCode: '',
        pudoId: '',
        customerId: '',
        refCustomerId: '',
        bussinessPhoneNumber: '',
        email: 'mail@ornek.com',
        taxOffice: '',
        taxNumber: '',
        homePhoneNumber: ''
    )
    ->test(false)
    ->send();

$dhlHareket = MPLogistics::dhl()
    ->account(
        username: 'MNG_CUSTOMER_NUMBER',
        password: 'MNG_PASSWORD',
        clientId: 'MNG_CLIENT_ID',
        clientSecret: 'MNG_CLIENT_SECRET'
    )
    ->test(false)
    ->kargoHareketleri('REF-1001');

$dhlDurum = MPLogistics::dhl()
    ->account(
        username: 'MNG_CUSTOMER_NUMBER',
        password: 'MNG_PASSWORD',
        clientId: 'MNG_CLIENT_ID',
        clientSecret: 'MNG_CLIENT_SECRET'
    )
    ->test(false)
    ->kargoDurum('REF-1001');
```

Iade gonderi:

```php
$response = MPLogistics::dhl()
    ->account(
        username: 'MNG_CUSTOMER_NUMBER',
        password: 'MNG_PASSWORD',
        clientId: 'MNG_CLIENT_ID',
        clientSecret: 'MNG_CLIENT_SECRET'
    )
    ->payload(
        referenceId: 'REF-1001',
        barcode: 'REF-1001',
        isCOD: false,
        codAmount: 0,
        shipmentServiceType: 1,
        packagingType: 1,
        paymentType: 2,
        deliveryType: 1,
        content: '1001',
        description: 'Order : 1001',
        cityCode: 34,
        cityName: 'ISTANBUL',
        districtName: 'KADIKOY',
        districtCode: 34001,
        address: 'Alici adresi',
        fullName: 'Alici Ad Soyad',
        mobilePhoneNumber: '905551112233',
        shipper: [
            'customerId' => '',
            'refCustomerId' => '',
            'cityCode' => 34,
            'cityName' => 'ISTANBUL',
            'districtName' => 'KADIKOY',
            'districtCode' => 34001,
            'address' => 'Gonderici adresi',
            'bussinessPhoneNumber' => '',
            'email' => 'mail@ornek.com',
            'taxOffice' => '',
            'taxNumber' => '',
            'fullName' => 'Gonderici Ad Soyad',
            'homePhoneNumber' => '',
            'mobilePhoneNumber' => '905551112233',
        ]
    )
    ->test(false)
    ->return();
```

- `MPLogistics::dhl()` DHL olarak adlandirildi (MNG API endpointlerini kullanir).
- `->return()` cagrisinda otomatik olarak `createReturnOrder` endpointine gider.
- Token temp dosyada cachelenir (`30 dakika`), suresi dolmadan yeniden token istenmez.

---

## HepsiJet Entegrasyonu

HepsiJet tarafinda iade yoktur.

```php
<?php

use MPYazilim\Logistics\MPLogistics;

$resp = MPLogistics::hepsijet()
    ->account(
        username: 'HEPSIJET_USERNAME',
        password: 'HEPSIJET_PASSWORD',
        companyName: 'SIRKET_ADI',
        companyCode: 'SIRKET_KODU'
    )
    ->payload(
        customerDeliveryNo: 'BARCODE_1001',
        customerOrderId: 'BARCODE_1001',
        totalParcels: '1',
        desi: '1',
        deliveryDateOriginal: '2026-02-07',
        deliveryType: 'RETAIL',
        productCode: 'HX_STD',
        receiverCompanyCustomerId: 'BARCODE_1001_ADDR_1',
        receiverFirstName: 'Ad',
        receiverLastName: 'Soyad',
        receiverPhone1: '905551112233',
        receiverEmail: 'mail@ornek.com',
        senderCompanyAddressId: 'SENDER_ADDRESS_ID',
        senderCityName: 'ISTANBUL',
        senderTownName: 'KADIKOY',
        senderDistrictName: 'SARUHAN',
        senderAddressLine1: 'Depo adresi',
        recipientCompanyAddressId: 'RECIPIENT_ADDR_1',
        recipientCityName: 'ISTANBUL',
        recipientTownName: 'KADIKOY',
        recipientDistrictName: '',
        recipientAddressLine1: 'Musteri adresi',
        recipientPerson: 'Ad Soyad',
        recipientPersonPhone1: '905551112233',
        countryName: 'Turkiye',
        deliverySlotOriginal: '0',
        extra: [] // kapida odeme gibi ek alanlar
    )
    ->test(false)
    ->send();

$takip = MPLogistics::hepsijet()
    ->account(
        username: 'HEPSIJET_USERNAME',
        password: 'HEPSIJET_PASSWORD',
        companyName: 'SIRKET_ADI',
        companyCode: 'SIRKET_KODU'
    )
    ->test(false)
    ->kargoTakip('BARCODE_1001');

$takipLink = MPLogistics::hepsijet()
    ->account(
        username: 'HEPSIJET_USERNAME',
        password: 'HEPSIJET_PASSWORD',
        companyName: 'SIRKET_ADI',
        companyCode: 'SIRKET_KODU'
    )
    ->test(false)
    ->takipLinkOlustur([
        'customerDeliveryNo' => 'BARCODE_1001',
    ]);

$sil = MPLogistics::hepsijet()
    ->account(
        username: 'HEPSIJET_USERNAME',
        password: 'HEPSIJET_PASSWORD',
        companyName: 'SIRKET_ADI',
        companyCode: 'SIRKET_KODU'
    )
    ->test(false)
    ->kargoSil('BARCODE_1001');
```

- HepsiJet token temp dosyada cachelenir (`30 dakika`).
- Token bitimine `5 dakika` kala yenilenir.
- Istersen `payloadRaw([...])` ile HepsiJet'e ham payload da gonderebilirsin.
