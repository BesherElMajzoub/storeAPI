<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\RefundNotAllowed;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminOrderResource;
use App\Models\Order;
use App\Models\RefundAttempt;
use App\Services\RefundService;
use App\Support\IdempotentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

/** Money returned outside Stripe (Zelle, PayPal) and corrections to it. */
class RefundController extends Controller
{
    public function __construct(private readonly RefundService $refunds) {}

    #[OA\Post(
        path: '/api/v1/admin/orders/{order}/refund/manual',
        summary: 'Admin – Record a manual refund',
        description: 'Record money already sent to the customer by **Zelle or PayPal** because the card route is closed (`refund.next_action = refund_manually`). This moves no money; it makes the shop records match. Ask the admin to confirm first. While a retry through Stripe is still offered (or the refund waits for a dispute or a check), `override_reason` is required. Send an `Idempotency-Key` header to make a double click harmless.',
        security: [['bearerAuth' => []]],
        tags: ['Admin Orders']
    )]
    #[OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['amount', 'method', 'external_reference', 'sent_at'],
        properties: [
            new OA\Property(property: 'amount', type: 'number', example: 41.30, description: 'Prefill with `refund.amount_owed`.'),
            new OA\Property(property: 'method', type: 'string', enum: ['zelle', 'paypal']),
            new OA\Property(property: 'external_reference', type: 'string', example: '8F3K29QX', description: 'The Zelle or PayPal transaction number.'),
            new OA\Property(property: 'sent_at', type: 'string', format: 'date-time'),
            new OA\Property(property: 'note', type: 'string', nullable: true),
            new OA\Property(property: 'override_reason', type: 'string', nullable: true),
        ]
    ))]
    #[OA\Response(response: 201, description: 'Recorded. `data.attempt` is the new entry and `data.order` the updated order.')]
    #[OA\Response(response: 409, description: '`refund_in_progress`, `retry_available` (no `override_reason`), `refund_blocked`, `nothing_owed`')]
    #[OA\Response(response: 422, description: '`amount_exceeds_owed` or field errors')]
    public function recordManual(Request $request, int $order): JsonResponse
    {
        $order = Order::findOrFail($order);

        return IdempotentRequest::run($request, "refund-manual:{$order->id}", function () use ($request, $order): JsonResponse {
            $validator = Validator::make($request->all(), [
                'amount' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,2})?$/'],
                'method' => ['required', 'in:zelle,paypal'],
                'external_reference' => ['required', 'string', 'max:120'],
                'sent_at' => ['required', 'date', 'before_or_equal:now'],
                'note' => ['nullable', 'string', 'max:1000'],
                'override_reason' => ['nullable', 'string', 'max:500'],
            ]);

            if ($validator->fails()) {
                return $this->error('The refund details are not valid.', 422, $validator->errors()->toArray());
            }

            $data = $validator->validated();

            try {
                $attempt = $this->refunds->recordManual(
                    $order,
                    $request->user(),
                    (int) round(((float) $data['amount']) * 100),
                    $data['method'],
                    trim($data['external_reference']),
                    Carbon::parse($data['sent_at']),
                    $data['note'] ?? null,
                    $data['override_reason'] ?? null,
                );
            } catch (RefundNotAllowed $e) {
                return $this->error($e->getMessage(), $e->httpStatus, $e->toErrors());
            }

            return $this->success($this->payload($order, $attempt), 'Manual refund recorded.', 201);
        });
    }

    #[OA\Post(
        path: '/api/v1/admin/orders/{order}/refund/attempts/{attempt}/void',
        summary: 'Admin – Void a manual refund',
        description: 'Correct a wrong manual entry (owner account only). The entry stays in the history, crossed out, and the amount owed goes back up. Stripe attempts cannot be voided.',
        security: [['bearerAuth' => []]],
        tags: ['Admin Orders']
    )]
    #[OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'attempt', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [
        new OA\Property(property: 'reason', type: 'string'),
    ]))]
    #[OA\Response(response: 200, description: 'Voided')]
    #[OA\Response(response: 403, description: 'Only the owner account can void a refund')]
    #[OA\Response(response: 409, description: '`not_voidable`: only a recorded manual refund can be voided')]
    #[OA\Response(response: 422, description: 'Reason missing')]
    public function void(Request $request, int $order, int $attempt): JsonResponse
    {
        if (! Gate::allows('void-refund')) {
            return $this->error('Only the owner account can void a refund.', 403);
        }

        $order = Order::findOrFail($order);
        $attempt = RefundAttempt::query()->where('order_id', $order->id)->findOrFail($attempt);

        $validator = Validator::make($request->all(), ['reason' => ['required', 'string', 'max:500']]);
        if ($validator->fails()) {
            return $this->error('A reason is required to void a refund.', 422, $validator->errors()->toArray());
        }

        try {
            $voided = $this->refunds->voidManual($order, $attempt, $request->user(), trim($validator->validated()['reason']));
        } catch (RefundNotAllowed $e) {
            return $this->error($e->getMessage(), $e->httpStatus, $e->toErrors());
        }

        return $this->success($this->payload($order, $voided), 'Manual refund voided.');
    }

    /** @return array<string, mixed> */
    private function payload(Order $order, RefundAttempt $attempt): array
    {
        $order = Order::with(['items', 'user', 'payment', 'cancellationRequest', 'refundAttempts.creator', 'refundAttempts.events'])->findOrFail($order->id);

        return [
            'attempt' => $this->refunds->presentAttempt($attempt->load('creator')),
            'order' => (new AdminOrderResource($order))->resolve(),
        ];
    }

    private function success(mixed $data, string $message, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data, 'errors' => null], $status);
    }

    private function error(string $message, int $status, mixed $errors = null): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'data' => null, 'errors' => $errors], $status);
    }
}
