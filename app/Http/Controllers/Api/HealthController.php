<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AppSettingsService;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(AppSettingsService $appSettings): JsonResponse
    {
        $settings = $appSettings->ensureDefaults();

        return response()->json([
            'status' => 'ok',
            'app' => $settings->app_name,
            'company' => $settings->company_name,
        ]);
    }
}
