<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function show(): JsonResponse
    {
        $version = config('app.version');

        return response()->json([
            'status' => 'ok',
            'version' => in_array($version, [null, '', 'unknown'], true) ? ($this->gitRevision() ?? 'unknown') : $version,
            'deployed_at' => config('app.deployed_at'),
        ]);
    }

    /**
     * Short commit SHA of a git-based deploy, used when APP_VERSION is not set.
     */
    private function gitRevision(): ?string
    {
        $head = @file_get_contents(base_path('.git/HEAD'));
        if ($head === false) {
            return null;
        }

        $head = trim($head);
        if (str_starts_with($head, 'ref: ')) {
            $head = @file_get_contents(base_path('.git/'.substr($head, 5)));
            if ($head === false) {
                return null;
            }
        }

        $sha = trim($head);

        return preg_match('/^[0-9a-f]{40}$/', $sha) === 1 ? substr($sha, 0, 7) : null;
    }
}
