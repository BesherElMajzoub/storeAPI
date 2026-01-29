<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateOrderStatusRequest;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);

        $orders = Order::query()
            ->with('user')
            ->when($request->filled('status'), fn($q) => $q->status($request->input('status')))
            ->when($request->filled('payment_status'), fn($q) => $q->paymentStatus($request->input('payment_status')))
            ->when($request->filled('user_id'), fn($q) => $q->where('user_id', (int) $request->input('user_id')))
            ->when($request->filled('search'), fn($q) => $q->search($request->input('search')))
            ->dateRange($request->input('date_from'), $request->input('date_to'))
            ->latest()
            ->paginate($perPage);

        return $this->success($orders, 'Orders fetched.');
    }

    public function show($id)
    {
        $order = Order::with(['items', 'user', 'payment'])->findOrFail($id);

        return $this->success($order, 'Order fetched.');
    }

    public function updateStatus(UpdateOrderStatusRequest $request, $id)
    {
        $order = Order::findOrFail($id);
        $data = $request->validated();

        $errors = [];
        if (array_key_exists('status', $data)) {
            if (!$this->canTransition($order->status, $data['status'], $this->statusTransitions())) {
                $errors['status'] = ['Invalid status transition.'];
            }
        }

        if (array_key_exists('payment_status', $data)) {
            if (!$this->canTransition($order->payment_status, $data['payment_status'], $this->paymentStatusTransitions())) {
                $errors['payment_status'] = ['Invalid payment status transition.'];
            }
        }

        if ($errors) {
            return $this->error('Status transition not allowed.', 409, $errors);
        }

        $order = DB::transaction(function () use ($order, $data) {
            $order->update($data);
            return $order->refresh()->load(['items', 'user', 'payment']);
        });

        return $this->success($order, 'Order status updated.');
    }

    private function statusTransitions(): array
    {
        return [
            'pending' => ['processing', 'cancelled'],
            'processing' => ['shipped', 'cancelled'],
            'shipped' => ['delivered', 'refunded'],
            'delivered' => ['refunded'],
            'cancelled' => [],
            'refunded' => [],
        ];
    }

    private function paymentStatusTransitions(): array
    {
        return [
            'unpaid' => ['paid', 'failed'],
            'paid' => ['refunded'],
            'failed' => ['paid'],
            'refunded' => [],
        ];
    }

    private function canTransition(string $from, string $to, array $map): bool
    {
        if ($from === $to) {
            return true;
        }

        return in_array($to, $map[$from] ?? [], true);
    }

    private function success($data, string $message, int $status = 200)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'errors' => null,
        ], $status);
    }

    private function error(string $message, int $status, $errors = null)
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'errors' => $errors,
        ], $status);
    }
}
