<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class AuditLogController extends Controller
{
    #[OA\Get(
        path: '/api/v1/admin/audit-logs',
        summary: 'List admin audit logs',
        security: [['bearerAuth' => []]],
        tags: ['Admin Audit Logs']
    )]
    #[OA\Parameter(name: 'action', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'causer_id', in: 'query', schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'date_from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Parameter(name: 'date_to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 50))]
    #[OA\Response(response: 200, description: 'Audit logs fetched')]
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['sometimes', 'string', 'max:255'],
            'causer_id' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'string', 'max:255'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $logs = AuditLog::query()
            ->with('causer')
            ->when($validated['action'] ?? null, fn ($query, string $action) => $query->where('action', $action))
            ->when($validated['causer_id'] ?? null, fn ($query, int $causerId) => $query->where('causer_id', $causerId))
            ->when($validated['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($searchQuery) use ($search): void {
                    $searchQuery->where('action', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%");
                });
            })
            ->when($validated['date_from'] ?? null, fn ($query, string $date) => $query->whereDate('created_at', '>=', $date))
            ->when($validated['date_to'] ?? null, fn ($query, string $date) => $query->whereDate('created_at', '<=', $date))
            ->latest()
            ->paginate((int) ($validated['per_page'] ?? 50))
            ->withQueryString();

        $logs->through(fn (AuditLog $log) => (new AuditLogResource($log))->resolve($request));

        return response()->json([
            'success' => true,
            'message' => 'Audit logs fetched.',
            'data' => $logs,
            'errors' => null,
        ]);
    }
}
