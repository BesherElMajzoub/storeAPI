<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'version' => config('app.version', 'unknown'),
            'deployed_at' => config('app.deployed_at'),
        ]);
    }
}
