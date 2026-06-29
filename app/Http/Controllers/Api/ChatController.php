<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AppSettingsService;
use App\Services\PhpToolOrchestratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ChatController extends Controller
{
    /**
     * Hard cap on prompt length to protect downstream model calls from oversized payloads/costs.
     */
    protected const MAX_MESSAGE_LENGTH = 4000;

    /**
     * Hard cap on how many uploaded artifacts can be attached to a single chat request.
     */
    protected const MAX_UPLOADED_FILES = 10;

    public function __invoke(Request $request, AppSettingsService $appSettings, PhpToolOrchestratorService $orchestrator): JsonResponse
    {
        $appSettings->ensureDefaults();

        $message = trim((string) $request->input('message', ''));
        if (mb_strlen($message) < 3) {
            return response()->json(['detail' => 'Describe la solicitud con mayor detalle.'], 422);
        }

        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            return response()->json([
                'detail' => 'El mensaje supera el limite de '.self::MAX_MESSAGE_LENGTH.' caracteres. Resumelo o divide la solicitud.',
            ], 422);
        }

        $uploadedFileIds = (array) $request->input('uploaded_file_ids', []);
        if (count($uploadedFileIds) > self::MAX_UPLOADED_FILES) {
            return response()->json([
                'detail' => 'Se permite un maximo de '.self::MAX_UPLOADED_FILES.' archivos adjuntos por solicitud.',
            ], 422);
        }

        try {
            return response()->json($orchestrator->chat($message, $uploadedFileIds));
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'detail' => 'La orquestacion Laravel falló. Revisa la configuracion, permisos de storage y los logs del backend.',
            ], 500);
        }
    }
}
