<?php

namespace App\Contracts;

interface EasyPostServiceInterface
{
    public function verifyAddress(array $address);

    public function getShippingRates(array $toAddress, array $parcel = [], array $carrierAccounts = []);

    public function purchaseLabel(string $shipmentId, string $rateId);

    public function retrieveRate(string $rateId);

    public function retrieveShipment(string $shipmentId);

    /**
     * URL of the shipment's label as a PDF, converting it first when it was bought in another format.
     */
    public function pdfLabelUrl(string $shipmentId): string;

    public function getTrackingStatus(string $trackerId);

    public function validateWebhook(string $payload, array $headers);
}
