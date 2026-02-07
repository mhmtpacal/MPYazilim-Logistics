<?php

declare(strict_types=1);

namespace MPYazilim\Logistics;

use MPYazilim\Logistics\Builders\ArasShipmentBuilder;
use MPYazilim\Logistics\Builders\DhlShipmentBuilder;
use MPYazilim\Logistics\Builders\HepsiJetShipmentBuilder;
use MPYazilim\Logistics\Builders\PttShipmentBuilder;
use MPYazilim\Logistics\Builders\UpsShipmentBuilder;
use MPYazilim\Logistics\Carriers\Aras\ArasCarrierAdapter;
use MPYazilim\Logistics\Carriers\Dhl\DhlCarrierAdapter;
use MPYazilim\Logistics\Carriers\HepsiJet\HepsiJetCarrierAdapter;
use MPYazilim\Logistics\Carriers\Ptt\PttCarrierAdapter;
use MPYazilim\Logistics\Carriers\Ups\UpsCarrierAdapter;

final class MPLogistics
{
    public static function ptt(): PttShipmentBuilder
    {
        return new PttShipmentBuilder(new PttCarrierAdapter());
    }

    public static function dhl(): DhlShipmentBuilder
    {
        return new DhlShipmentBuilder(new DhlCarrierAdapter());
    }

    public static function hepsijet(): HepsiJetShipmentBuilder
    {
        return new HepsiJetShipmentBuilder(new HepsiJetCarrierAdapter());
    }

    public static function aras(): ArasShipmentBuilder
    {
        return new ArasShipmentBuilder(new ArasCarrierAdapter());
    }

    public static function ups(): UpsShipmentBuilder
    {
        return new UpsShipmentBuilder(new UpsCarrierAdapter());
    }
}
