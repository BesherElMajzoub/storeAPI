<?php

namespace App\Contracts;

interface EasyPostServiceInterface
{
    public function verifyAddress(array $address);

    public function getShippingRates(array $toAddress, array $parcel = []);

    public function purchaseLabel(string $shipmentId, string $rateId);

    public function retrieveRate(string $rateId);

    public function retrieveShipment(string $shipmentId);

    public function getTrackingStatus(string $trackerId);

    public function validateWebhook(string $payload, array $headers);
}
