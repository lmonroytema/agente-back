<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AppSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppSettingsController extends Controller
{
    public function show(AppSettingsService $appSettings): JsonResponse
    {
        return response()->json(
            $appSettings->toPayload($appSettings->ensureDefaults())
        );
    }

    public function update(Request $request, AppSettingsService $appSettings): JsonResponse
    {
        $payload = $request->all();
        if (array_key_exists('allowed_domains', $payload)) {
            $payload['allowed_domains'] = $appSettings->normalizeDomains((array) $payload['allowed_domains']);
        }
        if (array_key_exists('admin_user_emails', $payload)) {
            $payload['admin_user_emails'] = $appSettings->normalizeEmails((array) $payload['admin_user_emails']);
        }
        if (array_key_exists('allowed_origins', $payload)) {
            $payload['allowed_origins'] = $appSettings->normalizeSimpleList((array) $payload['allowed_origins']);
        }

        $settings = $appSettings->updateSettings($payload);

        return response()->json($appSettings->toPayload($settings));
    }
}
