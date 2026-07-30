<?php

namespace App\Http\Controllers;

use App\Services\ApplicationReadiness;
use Illuminate\Http\JsonResponse;

class ReadinessController
{
    public function __invoke(ApplicationReadiness $readiness): JsonResponse
    {
        $isReady = $readiness->isReady();

        return response()
            ->json(['status' => $isReady ? 'ready' : 'unavailable'], $isReady ? 200 : 503)
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
