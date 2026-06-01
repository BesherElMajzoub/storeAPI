<?php

namespace App\Services;

use EasyPost\EasyPostClient;
use EasyPost\Exception\Api\ApiException;
use Exception;
use Illuminate\Support\Facades\Log;

class EasyPostService
{
    protected ?EasyPostClient $client = null;

    public function __construct()
    {
        $apiKey = config('services.easypost.api_key');
        if ($apiKey) {
            $this->client = new EasyPostClient($apiKey);
        } else {
            Log::warning('EasyPost API Key is not set in configuration.');
        }
    }

    /**
     * Verify a shipping address using EasyPost Address Verification API.
     *
     * @param array $address
     * @return array
     * @throws Exception
     */
    public function verifyAddress(array $address): array
    {
        if (!$this->client) {
            throw new Exception('EasyPost client is not configured.');
        }

        try {
            $addressParams = [
                'name'    => $address['name'] ?? null,
                'street1' => $address['street1'] ?? null,
                'street2' => $address['street2'] ?? null,
                'city'    => $address['city'] ?? null,
                'state'   => $address['state'] ?? null,
                'zip'     => $address['zip'] ?? null,
                'country' => $address['country'] ?? null,
                'phone'   => $address['phone'] ?? null,
                'verify'  => [true],
            ];

            $createdAddress = $this->client->address->create($addressParams);

            if (isset($createdAddress->verifications->delivery->success) && $createdAddress->verifications->delivery->success) {
                return [
                    'name'    => $createdAddress->name,
                    'street1' => $createdAddress->street1,
                    'street2' => $createdAddress->street2,
                    'city'    => $createdAddress->city,
                    'state'   => $createdAddress->state,
                    'zip'     => $createdAddress->zip,
                    'country' => $createdAddress->country,
                    'phone'   => $createdAddress->phone,
                ];
            }

            // Extract verification errors
            $errors = [];
            if (isset($createdAddress->verifications->delivery->errors)) {
                foreach ($createdAddress->verifications->delivery->errors as $error) {
                    $errors[] = $error->message;
                }
            }

            $errorMessage = count($errors) > 0 ? implode('; ', $errors) : 'Address verification failed.';
            throw new Exception($errorMessage);

        } catch (ApiException $e) {
            Log::error('EasyPost Address Verification API error: ' . $e->getMessage());
            throw new Exception('EasyPost Address Verification failed: ' . $e->getMessage());
        }
    }

    /**
     * Create an EasyPost Shipment and get available rates.
     *
     * @param array $toAddress
     * @param array $parcel
     * @return \EasyPost\Shipment
     * @throws Exception
     */
    public function getShippingRates(array $toAddress, array $parcel = [])
    {
        if (!$this->client) {
            throw new Exception('EasyPost client is not configured.');
        }

        try {
            $fromAddress = config('services.store_origin');

            // Default parcel size if none provided (e.g. standard package 1 lb)
            if (empty($parcel)) {
                $parcel = [
                    'weight' => 16.0, // 16 oz = 1 lb
                    'length' => 10.0,
                    'width'  => 8.0,
                    'height' => 4.0,
                ];
            }

            $shipment = $this->client->shipment->create([
                'to_address'   => [
                    'name'    => $toAddress['name'] ?? null,
                    'street1' => $toAddress['street1'] ?? null,
                    'street2' => $toAddress['street2'] ?? null,
                    'city'    => $toAddress['city'] ?? null,
                    'state'   => $toAddress['state'] ?? null,
                    'zip'     => $toAddress['zip'] ?? null,
                    'country' => $toAddress['country'] ?? null,
                    'phone'   => $toAddress['phone'] ?? null,
                ],
                'from_address' => $fromAddress,
                'parcel'       => $parcel,
            ]);

            return $shipment;

        } catch (ApiException $e) {
            Log::error('EasyPost Shipping Rates API error: ' . $e->getMessage());
            throw new Exception('Failed to get shipping rates: ' . $e->getMessage());
        }
    }

    /**
     * Buy a postage label for a shipment.
     *
     * @param string $shipmentId
     * @param string $rateId
     * @return \EasyPost\Shipment
     * @throws Exception
     */
    public function purchaseLabel(string $shipmentId, string $rateId)
    {
        if (!$this->client) {
            throw new Exception('EasyPost client is not configured.');
        }

        try {
            // Purchase the shipment using the selected rate ID
            $shipment = $this->client->shipment->buy($shipmentId, ['rate' => ['id' => $rateId]]);
            return $shipment;
        } catch (ApiException $e) {
            Log::error('EasyPost Shipment Purchase API error: ' . $e->getMessage());
            throw new Exception('Failed to purchase shipping label: ' . $e->getMessage());
        }
    }

    /**
     * Retrieve a shipment by its ID.
     *
     * @param string $shipmentId
     * @return \EasyPost\Shipment
     * @throws Exception
     */
    public function retrieveShipment(string $shipmentId)
    {
        if (!$this->client) {
            throw new Exception('EasyPost client is not configured.');
        }

        try {
            return $this->client->shipment->retrieve($shipmentId);
        } catch (ApiException $e) {
            Log::error('EasyPost Shipment Retrieve API error: ' . $e->getMessage());
            throw new Exception('Failed to retrieve shipment: ' . $e->getMessage());
        }
    }

    /**
     * Retrieve tracking status for a tracker/shipment.
     *
     * @param string $trackerId
     * @return \EasyPost\Tracker
     * @throws Exception
     */
    public function getTrackingStatus(string $trackerId)
    {
        if (!$this->client) {
            throw new Exception('EasyPost client is not configured.');
        }

        try {
            $tracker = $this->client->tracker->retrieve($trackerId);
            return $tracker;
        } catch (ApiException $e) {
            Log::error('EasyPost Tracker Retrieve error: ' . $e->getMessage());
            throw new Exception('Failed to retrieve tracking info: ' . $e->getMessage());
        }
    }

    /**
     * Validate an EasyPost webhook signature and return the parsed Event.
     *
     * @param string $payload Raw request body
     * @param array $headers Request headers
     * @return \EasyPost\Event
     * @throws Exception
     */
    public function validateWebhook(string $payload, array $headers)
    {
        if (!$this->client) {
            throw new Exception('EasyPost client is not configured.');
        }

        $secret = config('services.easypost.webhook_secret');
        if (empty($secret)) {
            Log::warning('EasyPost Webhook Secret is not set. Skipping signature verification (ONLY FOR DEV!).');
            // If secret is not set, decode payload directly for testing
            $data = json_decode($payload);
            if (!$data) {
                throw new Exception('Invalid JSON payload.');
            }
            return $data;
        }

        try {
            // Flat map headers to ensure easy resolution
            $flatHeaders = collect($headers)->map(fn($item) => is_array($item) ? reset($item) : $item)->toArray();
            
            $event = $this->client->webhook->validateWebhook($payload, $flatHeaders, $secret);
            return $event;
        } catch (Exception $e) {
            Log::error('EasyPost Webhook Signature Validation error: ' . $e->getMessage());
            throw new Exception('Invalid EasyPost webhook signature: ' . $e->getMessage());
        }
    }
}
