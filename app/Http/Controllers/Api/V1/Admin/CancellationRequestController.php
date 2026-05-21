<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\RejectCancellationRequestRequest;
use App\Http\Resources\CancellationRequestResource;
use App\Mail\CancellationRequestDecidedMail;
use App\Models\OrderCancellationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use OpenApi\Attributes as OA;

class CancellationRequestController extends Controller
{
    // ── List ─────────────────────────────────────────────────────────────────

    #[OA\Get(
        path: "/api/v1/admin/cancellation-requests",
        summary: "Admin – List Cancellation Requests",
        description: "Paginated list of order cancellation requests. Filter by `status` (pending/accepted/rejected).",
        security: [["bearerAuth" => []]],
        tags: ["Admin Cancellation Requests"]
    )]
    #[OA\Parameter(name: "status",   in: "query", schema: new OA\Schema(type: "string", enum: ["pending", "accepted", "rejected"]))]
    #[OA\Parameter(name: "per_page", in: "query", schema: new OA\Schema(type: "integer", default: 20))]
    #[OA\Response(
        response: 200,
        description: "Requests fetched",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(property: "success", type: "boolean"),
                new OA\Property(property: "data",    type: "object"),
            ]
        )
    )]
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);
        $status  = $request->query('status');

        $requests = OrderCancellationRequest::with(['order', 'user'])
            ->byStatus($status)
            ->latest()
            ->paginate($perPage);

        return $this->success(
            CancellationRequestResource::collection($requests)->response()->getData(true),
            'Cancellation requests fetched.'
        );
    }

    // ── Accept ───────────────────────────────────────────────────────────────

    #[OA\Post(
        path: "/api/v1/admin/cancellation-requests/{id}/accept",
        summary: "Admin – Accept Cancellation Request",
        description: "Accept the request: cancel the order, restock variants, and email the user.",
        security: [["bearerAuth" => []]],
        tags: ["Admin Cancellation Requests"]
    )]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\Response(response: 200, description: "Request accepted, order cancelled")]
    #[OA\Response(response: 409, description: "Request already decided")]
    #[OA\Response(response: 404, ref: "#/components/responses/ErrorResponse")]
    public function accept(Request $request, int $id): JsonResponse
    {
        $cancellation = OrderCancellationRequest::with(['order.items.product', 'user'])->findOrFail($id);

        if ($cancellation->status !== 'pending') {
            return $this->error("This request has already been {$cancellation->status}.", 409);
        }

        DB::transaction(function () use ($cancellation, $request) {
            $order = $cancellation->order;

            // Cancel the order
            $order->update(['status' => 'cancelled']);

            // Restock variants/products for each order item
            foreach ($order->items as $item) {
                $product = $item->product;
                if ($product) {
                    $product->increment('stock_qty', $item->quantity);
                    // Also update variants if the order item had a variant_id
                    if ($item->variant_id) {
                        $product->variants()
                            ->where('id', $item->variant_id)
                            ->increment('stock_qty', $item->quantity);
                    }
                }
            }

            // Mark the cancellation request as accepted
            $cancellation->update([
                'status'     => 'accepted',
                'admin_id'   => $request->user()->id,
                'decided_at' => now(),
            ]);
        });

        // Notify user
        Mail::to($cancellation->user->email)
            ->queue(new CancellationRequestDecidedMail($cancellation->refresh(), 'accepted'));

        return $this->success(
            ['message' => 'Cancellation request accepted. Order cancelled and user notified.'],
            'Request accepted.'
        );
    }

    // ── Reject ───────────────────────────────────────────────────────────────

    #[OA\Post(
        path: "/api/v1/admin/cancellation-requests/{id}/reject",
        summary: "Admin – Reject Cancellation Request",
        description: "Reject the request. Order is left untouched. An email with the admin note is sent to the user.",
        security: [["bearerAuth" => []]],
        tags: ["Admin Cancellation Requests"]
    )]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ["admin_note"],
            properties: [
                new OA\Property(property: "admin_note", type: "string", minLength: 5, example: "Your order has already been dispatched and cannot be cancelled."),
            ]
        )
    )]
    #[OA\Response(response: 200, description: "Request rejected, user notified")]
    #[OA\Response(response: 409, description: "Request already decided")]
    #[OA\Response(response: 404, ref: "#/components/responses/ErrorResponse")]
    public function reject(RejectCancellationRequestRequest $request, int $id): JsonResponse
    {
        $cancellation = OrderCancellationRequest::with(['order', 'user'])->findOrFail($id);

        if ($cancellation->status !== 'pending') {
            return $this->error("This request has already been {$cancellation->status}.", 409);
        }

        $cancellation->update([
            'status'     => 'rejected',
            'admin_id'   => $request->user()->id,
            'admin_note' => $request->validated('admin_note'),
            'decided_at' => now(),
        ]);

        // Notify user
        Mail::to($cancellation->user->email)
            ->queue(new CancellationRequestDecidedMail($cancellation->refresh(), 'rejected'));

        return $this->success(
            ['message' => 'Cancellation request rejected. User has been notified.'],
            'Request rejected.'
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function success($data, string $message, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
            'errors'  => null,
        ], $status);
    }

    private function error(string $message, int $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data'    => null,
            'errors'  => null,
        ], $status);
    }
}
