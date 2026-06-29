<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AppSettingsService;
use App\Services\FileArtifactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

class FileController extends Controller
{
    /**
     * Maximum size per uploaded file, in kilobytes (Laravel "max" rule unit).
     * 25 MB keeps PDFs/office docs usable while protecting storage and Anthropic prompt size.
     */
    protected const MAX_FILE_SIZE_KB = 25 * 1024;

    /**
     * Maximum number of files accepted in a single upload request.
     */
    protected const MAX_FILES_PER_REQUEST = 10;

    protected const ALLOWED_EXTENSIONS = ['pdf', 'docx', 'xlsx', 'pptx', 'doc', 'xls', 'ppt', 'txt', 'csv', 'png', 'jpg', 'jpeg', 'webp'];

    public function upload(Request $request, AppSettingsService $appSettings, FileArtifactService $files): JsonResponse
    {
        $appSettings->ensureDefaults();
        $uploadedFiles = $request->file('files');

        if (! $uploadedFiles) {
            return response()->json(['detail' => 'No se recibieron archivos.'], 400);
        }

        $normalized = is_array($uploadedFiles) ? $uploadedFiles : [$uploadedFiles];

        if (count($normalized) > self::MAX_FILES_PER_REQUEST) {
            return response()->json([
                'detail' => 'Se permite un maximo de '.self::MAX_FILES_PER_REQUEST.' archivos por solicitud.',
            ], 422);
        }

        try {
            $request->validate([
                'files' => ['required', 'array', 'max:'.self::MAX_FILES_PER_REQUEST],
                'files.*' => [
                    'file',
                    'max:'.self::MAX_FILE_SIZE_KB,
                    'mimes:'.implode(',', self::ALLOWED_EXTENSIONS),
                ],
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'detail' => 'Archivo invalido: revisa el tamano (limite '.number_format(self::MAX_FILE_SIZE_KB / 1024, 0).' MB) y el tipo permitido.',
                'errors' => $exception->errors(),
            ], 422);
        }

        return response()->json($files->saveUploads($normalized));
    }

    public function show(string $artifactId, AppSettingsService $appSettings, FileArtifactService $files)
    {
        $appSettings->ensureDefaults();
        $path = $files->getFilePath($artifactId);

        if (! $path) {
            return response()->json(['detail' => 'Archivo no encontrado'], 404);
        }

        return response()->download(
            $path,
            basename($path),
            ['Content-Type' => File::mimeType($path) ?: 'application/octet-stream']
        );
    }
}
