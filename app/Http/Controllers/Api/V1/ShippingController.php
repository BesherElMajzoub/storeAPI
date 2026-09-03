<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ShippingProviderException;
use App\Exceptions\ShippingValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GetShippingRatesRequest;
use App\Http\Requests\Api\V1\VerifyAddressRequest;
use App\Http\Resources\ShippingRateResource;
use App\Services\EasyPostService;
use App\Services\ShippingQuoteService;
use Exception;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class ShippingController extends Controller
{
    protected EasyPostService $easyPostService;

    public function __construct(EasyPostService $easyPostService, private readonly ShippingQuoteService $quotes)
    {
        $this->easyPostService = $easyPostService;
    }

    #[OA\Post(
        path: '/api/v1/shipping/verify-address',
        summary: 'Verify Shipping Address',
        description: 'Validate shipping address details using the EasyPost address verification API.',
        tags: ['Shipping']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['name', 'street1', 'city', 'state', 'zip', 'country'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'John Doe'),
                new OA\Property(property: 'street1', type: 'string', example: '179 N Harbor Dr'),
                new OA\Property(property: 'street2', type: 'string', nullable: true, example: null),
                new OA\Property(property: 'city', type: 'string', example: 'Redondo Beach'),
                new OA\Property(property: 'state', type: 'string', example: 'CA'),
                new OA\Property(property: 'zip', type: 'string', example: '90277'),
                new OA\Property(property: 'country', type: 'string', example: 'US', description: '2-letter ISO country code'),
                new OA\Property(property: 'phone', type: 'string', nullable: true, example: '310-808-5243'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Address is valid.',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Address is valid.'),
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'name', type: 'string', example: 'JOHN DOE'),
                        new OA\Property(property: 'street1', type: 'string', example: '179 N HARBOR DR'),
                        new OA\Property(property: 'street2', type: 'string', nullable: true, example: null),
                        new OA\Property(property: 'city', type: 'string', example: 'REDONDO BEACH'),
                        new OA\Property(property: 'state', type: 'string', example: 'CA'),
                        new OA\Property(property: 'zip', type: 'string', example: '90277-2510'),
                        new OA\Property(property: 'country', type: 'string', example: 'US'),
                        new OA\Property(property: 'phone', type: 'string', example: '3108085243'),
                    ]
                ),
                new OA\Property(property: 'errors', type: 'object', nullable: true, example: null),
            ]
        )
    )]
    #[OA\Response(
        response: 422,
        description: 'Address verification failed.',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Address verification failed: street1 is invalid.'),
                new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
                new OA\Property(
                    property: 'errors',
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'address',
                            type: 'array',
                            items: new OA\Items(type: 'string'),
                            example: ['Address verification failed: street1 is invalid.']
                        ),
                    ]
                ),
            ]
        )
    )]
    public function verifyAddress(VerifyAddressRequest $request): JsonResponse
    {
        try {
            $verified = $this->easyPostService->verifyAddress($request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Address is valid.',
                'data' => $verified,
                'errors' => null,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => [
                    'address' => [$e->getMessage()],
                ],
            ], 422);
        }
    }

    #[OA\Post(
        path: '/api/v1/shipping/rates',
        summary: 'Get Shipping Rates',
        description: 'Get real-time US shipping rates from server-side product dimensions. The legacy address + parcel shape remains deprecated in API v1.',
        tags: ['Shipping']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['address', 'items'],
            properties: [
                new OA\Property(
                    property: 'address',
                    type: 'object',
                    required: ['line1', 'city', 'state', 'postal_code', 'country'],
                    properties: [
                        new OA\Property(property: 'line1', type: 'string', example: '179 N Harbor Dr'),
                        new OA\Property(property: 'line2', type: 'string', nullable: true, example: null),
                        new OA\Property(property: 'city', type: 'string', example: 'Redondo Beach'),
                        new OA\Property(property: 'state', type: 'string', example: 'CA'),
                        new OA\Property(property: 'postal_code', type: 'string', example: '90277'),
                        new OA\Property(property: 'country', type: 'string', example: 'US'),
                        new OA\Property(property: 'phone', type: 'string', nullable: true, example: '310-808-5243'),
                    ]
                ),
                new OA\Property(
                    property: 'items',
                    type: 'array',
                    items: new OA\Items(
                        required: ['product_id', 'quantity'],
                        properties: [
                            new OA\Property(property: 'product_id', type: 'integer'),
                            new OA\Property(property: 'variant_id', type: 'integer', nullable: true),
                            new OA\Property(property: 'quantity', type: 'integer', minimum: 1),
                        ]
                    )
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Shipping rates retrieved.',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Shipping rates retrieved.'),
                new OA\Property(
                    property: 'data',
                    type: 'array',
                    items: new OA\Items(properties: [
                        new OA\Property(property: 'rate_id', type: 'string', example: 'rate_abc123'),
                        new OA\Property(property: 'carrier', type: 'string', example: 'USPS'),
                        new OA\Property(property: 'service', type: 'string', example: 'Priority'),
                        new OA\Property(property: 'amount', type: 'number', format: 'float', example: 8.45),
                        new OA\Property(property: 'eta_days', type: 'integer', nullable: true, example: 3),
                    ])
                ),
                new OA\Property(property: 'errors', type: 'object', nullable: true, example: null),
            ]
        )
    )]
    #[OA\Response(response: 503, description: 'Shipping provider unavailable')]
    #[OA\Response(
        response: 422,
        description: 'Failed to retrieve rates.',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Failed to get shipping rates.'),
                new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
                new OA\Property(
                    property: 'errors',
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'shipping',
                            type: 'array',
                            items: new OA\Items(type: 'string'),
                            example: ['Carrier accounts not configured.']
                        ),
                    ]
                ),
            ]
        )
    )]
    public function getRates(GetShippingRatesRequest $request): JsonResponse
    {
        try {
            if ($request->has('items')) {
                $rates = $this->quotes->quote(
                    $request->validated('address'),
                    $request->validated('items')
                );

                return response()->json([
                    'success' => true,
                    'message' => 'Shipping rates retrieved.',
                    'data' => $rates,
                    'errors' => null,
                ]);
            }

            // Deprecated v1 compatibility contract: address + caller-supplied parcel.
            $address = $this->quotes->normalizeAddress($request->validated('address'));
            $shipment = $this->easyPostService->getShippingRates(
                $address,
                $request->validated('parcel')
            );
            $this->quotes->storeLegacyQuotes($shipment, $address, $request->validated('parcel'));

            return response()->json([
                'success' => true,
                'message' => 'Shipping rates retrieved.',
                'data' => [
                    'shipment_id' => $shipment->id,
                    'rates' => ShippingRateResource::collection($shipment->rates),
                ],
                'errors' => null,
            ])->header('Deprecation', 'true');
        } catch (ShippingValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => [
                    'shipping' => [$e->getMessage()],
                    'code' => $e->errorCode,
                ],
            ], 422);
        } catch (ShippingProviderException|Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Shipping rates are temporarily unavailable.',
                'data' => null,
                'errors' => ['shipping' => ['Shipping provider request failed.']],
            ], 503);
        }
    }
}
