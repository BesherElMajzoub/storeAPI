<?php

namespace App\Http\Controllers\Api\V1\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Laravel\Telescope\Storage\EntryQueryOptions;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\EntryType;
use OpenApi\Attributes as OA;

/**
 * Telescope API Proxy
 *
 * يسمح للـ React admin panel بقراءة بيانات Telescope
 * عبر Sanctum Bearer token بدلاً من session.
 *
 * محمي بـ: auth:sanctum + can:admin-access
 */
class TelescopeApiController extends Controller
{
    public function __construct(protected EntriesRepository $storage) {}

    // ─── Requests ────────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/v1/admin/telescope/requests',
        summary: 'Get Telescope Requests',
        description: 'Get logged HTTP requests from Telescope',
        security: [['bearerAuth' => []]],
        tags: ['Admin Telescope']
    )]
    #[OA\Parameter(name: 'before', in: 'query', required: false, description: 'Cursor for pagination (UUID)', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'take', in: 'query', required: false, description: 'Number of items to return', schema: new OA\Schema(type: 'integer', default: 50))]
    #[OA\Response(response: 200, description: 'Success')]
    public function requests(Request $request): JsonResponse
    {
        $options = $this->buildOptions($request, EntryType::REQUEST);
        $entries = $this->storage->get(EntryType::REQUEST, $options);

        return response()->json([
            'success' => true,
            'data'    => $this->formatEntries($entries),
        ]);
    }

    // ─── Queries ─────────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/v1/admin/telescope/queries',
        summary: 'Get Telescope Queries',
        description: 'Get logged database queries from Telescope',
        security: [['bearerAuth' => []]],
        tags: ['Admin Telescope']
    )]
    #[OA\Parameter(name: 'before', in: 'query', required: false, description: 'Cursor for pagination (UUID)', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'take', in: 'query', required: false, description: 'Number of items to return', schema: new OA\Schema(type: 'integer', default: 50))]
    #[OA\Response(response: 200, description: 'Success')]
    public function queries(Request $request): JsonResponse
    {
        $options = $this->buildOptions($request, EntryType::QUERY);
        $entries = $this->storage->get(EntryType::QUERY, $options);

        return response()->json([
            'success' => true,
            'data'    => $this->formatEntries($entries),
        ]);
    }

    // ─── Exceptions ───────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/v1/admin/telescope/exceptions',
        summary: 'Get Telescope Exceptions',
        description: 'Get logged exceptions from Telescope',
        security: [['bearerAuth' => []]],
        tags: ['Admin Telescope']
    )]
    #[OA\Parameter(name: 'before', in: 'query', required: false, description: 'Cursor for pagination (UUID)', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'take', in: 'query', required: false, description: 'Number of items to return', schema: new OA\Schema(type: 'integer', default: 50))]
    #[OA\Response(response: 200, description: 'Success')]
    public function exceptions(Request $request): JsonResponse
    {
        $options = $this->buildOptions($request, EntryType::EXCEPTION);
        $entries = $this->storage->get(EntryType::EXCEPTION, $options);

        return response()->json([
            'success' => true,
            'data'    => $this->formatEntries($entries),
        ]);
    }

    // ─── Jobs ────────────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/v1/admin/telescope/jobs',
        summary: 'Get Telescope Jobs',
        description: 'Get logged queue jobs from Telescope',
        security: [['bearerAuth' => []]],
        tags: ['Admin Telescope']
    )]
    #[OA\Parameter(name: 'before', in: 'query', required: false, description: 'Cursor for pagination (UUID)', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'take', in: 'query', required: false, description: 'Number of items to return', schema: new OA\Schema(type: 'integer', default: 50))]
    #[OA\Response(response: 200, description: 'Success')]
    public function jobs(Request $request): JsonResponse
    {
        $options = $this->buildOptions($request, EntryType::JOB);
        $entries = $this->storage->get(EntryType::JOB, $options);

        return response()->json([
            'success' => true,
            'data'    => $this->formatEntries($entries),
        ]);
    }

    // ─── Logs ────────────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/v1/admin/telescope/logs',
        summary: 'Get Telescope Logs',
        description: 'Get logged application logs from Telescope',
        security: [['bearerAuth' => []]],
        tags: ['Admin Telescope']
    )]
    #[OA\Parameter(name: 'before', in: 'query', required: false, description: 'Cursor for pagination (UUID)', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'take', in: 'query', required: false, description: 'Number of items to return', schema: new OA\Schema(type: 'integer', default: 50))]
    #[OA\Response(response: 200, description: 'Success')]
    public function logs(Request $request): JsonResponse
    {
        $options = $this->buildOptions($request, EntryType::LOG);
        $entries = $this->storage->get(EntryType::LOG, $options);

        return response()->json([
            'success' => true,
            'data'    => $this->formatEntries($entries),
        ]);
    }

    // ─── Events ──────────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/v1/admin/telescope/events',
        summary: 'Get Telescope Events',
        description: 'Get logged events from Telescope',
        security: [['bearerAuth' => []]],
        tags: ['Admin Telescope']
    )]
    #[OA\Parameter(name: 'before', in: 'query', required: false, description: 'Cursor for pagination (UUID)', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'take', in: 'query', required: false, description: 'Number of items to return', schema: new OA\Schema(type: 'integer', default: 50))]
    #[OA\Response(response: 200, description: 'Success')]
    public function events(Request $request): JsonResponse
    {
        $options = $this->buildOptions($request, EntryType::EVENT);
        $entries = $this->storage->get(EntryType::EVENT, $options);

        return response()->json([
            'success' => true,
            'data'    => $this->formatEntries($entries),
        ]);
    }

    // ─── Mail ────────────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/v1/admin/telescope/mail',
        summary: 'Get Telescope Mail',
        description: 'Get logged emails from Telescope',
        security: [['bearerAuth' => []]],
        tags: ['Admin Telescope']
    )]
    #[OA\Parameter(name: 'before', in: 'query', required: false, description: 'Cursor for pagination (UUID)', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'take', in: 'query', required: false, description: 'Number of items to return', schema: new OA\Schema(type: 'integer', default: 50))]
    #[OA\Response(response: 200, description: 'Success')]
    public function mail(Request $request): JsonResponse
    {
        $options = $this->buildOptions($request, EntryType::MAIL);
        $entries = $this->storage->get(EntryType::MAIL, $options);

        return response()->json([
            'success' => true,
            'data'    => $this->formatEntries($entries),
        ]);
    }

    // ─── Notifications ───────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/v1/admin/telescope/notifications',
        summary: 'Get Telescope Notifications',
        description: 'Get logged notifications from Telescope',
        security: [['bearerAuth' => []]],
        tags: ['Admin Telescope']
    )]
    #[OA\Parameter(name: 'before', in: 'query', required: false, description: 'Cursor for pagination (UUID)', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'take', in: 'query', required: false, description: 'Number of items to return', schema: new OA\Schema(type: 'integer', default: 50))]
    #[OA\Response(response: 200, description: 'Success')]
    public function notifications(Request $request): JsonResponse
    {
        $options = $this->buildOptions($request, EntryType::NOTIFICATION);
        $entries = $this->storage->get(EntryType::NOTIFICATION, $options);

        return response()->json([
            'success' => true,
            'data'    => $this->formatEntries($entries),
        ]);
    }

    // ─── Cache ───────────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/v1/admin/telescope/cache',
        summary: 'Get Telescope Cache',
        description: 'Get logged cache operations from Telescope',
        security: [['bearerAuth' => []]],
        tags: ['Admin Telescope']
    )]
    #[OA\Parameter(name: 'before', in: 'query', required: false, description: 'Cursor for pagination (UUID)', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'take', in: 'query', required: false, description: 'Number of items to return', schema: new OA\Schema(type: 'integer', default: 50))]
    #[OA\Response(response: 200, description: 'Success')]
    public function cache(Request $request): JsonResponse
    {
        $options = $this->buildOptions($request, EntryType::CACHE);
        $entries = $this->storage->get(EntryType::CACHE, $options);

        return response()->json([
            'success' => true,
            'data'    => $this->formatEntries($entries),
        ]);
    }

    // ─── Summary (نظرة عامة) ────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/v1/admin/telescope/summary',
        summary: 'Get Telescope Overview Summary',
        description: 'Get a summary count and latest entry of all monitored Telescope types',
        security: [['bearerAuth' => []]],
        tags: ['Admin Telescope']
    )]
    #[OA\Response(response: 200, description: 'Success')]
    public function summary(): JsonResponse
    {
        $types = [
            'requests'      => EntryType::REQUEST,
            'exceptions'    => EntryType::EXCEPTION,
            'jobs'          => EntryType::JOB,
            'logs'          => EntryType::LOG,
            'queries'       => EntryType::QUERY,
            'mail'          => EntryType::MAIL,
            'notifications' => EntryType::NOTIFICATION,
            'cache'         => EntryType::CACHE,
            'events'        => EntryType::EVENT,
        ];

        $summary = [];
        foreach ($types as $label => $type) {
            $options = EntryQueryOptions::forIndex($type, null, 50);
            $entries = $this->storage->get($type, $options);
            $summary[$label] = [
                'count'   => count($entries),
                'latest'  => collect($entries)->first()?->content ?? null,
            ];
        }

        return response()->json([
            'success' => true,
            'data'    => $summary,
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────

    private function buildOptions(Request $request, string $type): EntryQueryOptions
    {
        return EntryQueryOptions::forIndex(
            $type,
            $request->query('before'),   // pagination cursor — UUID of last entry seen
            (int) $request->query('take', 50)
        );
    }

    private function formatEntries(array $entries): array
    {
        return collect($entries)->map(fn ($entry) => [
            'id'         => $entry->id,
            'uuid'       => $entry->uuid,
            'type'       => $entry->type,
            'content'    => $entry->content,
            'tags'       => $entry->tags,
            'created_at' => $entry->createdAt,
        ])->values()->all();
    }
}
