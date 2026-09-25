<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateShippingSettingsRequest;
use App\Services\FreeShippingService;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class SettingController extends Controller
{
    use ApiResponseTrait;

    #[OA\Get(
        path: '/api/v1/admin/settings/shipping',
        summary: 'Get shipping settings',
        description: 'Returns the current automatic free-shipping configuration.',
        security: [['bearerAuth' => []]],
        tags: ['Admin Settings']
    )]
    #[OA\Response(response: 200, description: 'Shipping settings')]
    public function showShipping(FreeShippingService $freeShipping): JsonResponse
    {
        return $this->success($this->shippingSettingsPayload($freeShipping));
    }

    #[OA\Put(
        path: '/api/v1/admin/settings/shipping',
        summary: 'Update shipping settings',
        description: 'Enable or disable automatic free shipping and set its minimum subtotal threshold.',
        security: [['bearerAuth' => []]],
        tags: ['Admin Settings']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['free_shipping_enabled'],
            properties: [
                new OA\Property(property: 'free_shipping_enabled', type: 'boolean', example: true),
                new OA\Property(property: 'free_shipping_threshold', type: 'number', format: 'float', example: 100, nullable: true),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Shipping settings updated')]
    #[OA\Response(response: 422, ref: '#/components/responses/ValidationErrorResponse')]
    public function updateShipping(UpdateShippingSettingsRequest $request, FreeShippingService $freeShipping): JsonResponse
    {
        $data = $request->validated();

        $freeShipping->update(
            (bool) $data['free_shipping_enabled'],
            isset($data['free_shipping_threshold']) ? (float) $data['free_shipping_threshold'] : null
        );

        return $this->success($this->shippingSettingsPayload($freeShipping), 'Shipping settings updated.');
    }

    private function shippingSettingsPayload(FreeShippingService $freeShipping): array
    {
        return [
            'free_shipping_enabled' => $freeShipping->isEnabled(),
            'free_shipping_threshold' => $freeShipping->threshold(),
        ];
    }
}
