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
     * Default maximum size per uploaded file, in megabytes.
     */
    protected const DEFAULT_MAX_FILE_SIZE_MB = 1024;

    /**
     * Default maximum number of files accepted in a single upload request.
     */
    protected const DEFAULT_MAX_FILES_PER_REQUEST = 10;

    protected const ALLOWED_EXTENSIONS = ['pdf', 'docx', 'xlsx', 'pptx', 'doc', 'xls', 'ppt', 'txt', 'csv', 'png', 'jpg', 'jpeg', 'webp'];

    public function upload(Request $request, AppSettingsService $appSettings, FileArtifactService $files): JsonResponse
    {
        $appSettings->ensureDefaults();
        $uploadedFiles = $request->file('files');
        $maxFilesPerRequest = $this->maxFilesPerRequest();
        $maxFileSizeKb = $this->maxFileSizeKb();

        if (! $uploadedFiles) {
            return response()->json(['detail' => 'No se recibieron archivos.'], 400);
        }

        $normalized = is_array($uploadedFiles) ? $uploadedFiles : [$uploadedFiles];

        if (count($normalized) > $maxFilesPerRequest) {
            return response()->json([
                'detail' => 'Se permite un maximo de '.$maxFilesPerRequest.' archivos por solicitud.',
            ], 422);
        }

        try {
            $request->validate([
                'files' => ['required', 'array', 'max:'.$maxFilesPerRequest],
                'files.*' => [
                    'file',
                    'max:'.$maxFileSizeKb,
                    'mimes:'.implode(',', self::ALLOWED_EXTENSIONS),
                ],
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'detail' => 'Archivo invalido: revisa el tamano (limite actual '.number_format($maxFileSizeKb / 1024, 0).' MB) y el tipo permitido.',
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

    protected function maxFilesPerRequest(): int
    {
        return max(1, (int) env('APP_MAX_FILES_PER_REQUEST', self::DEFAULT_MAX_FILES_PER_REQUEST));
    }

    protected function maxFileSizeKb(): int
    {
        $appLimitKb = max(1, (int) env('APP_MAX_UPLOAD_MB', self::DEFAULT_MAX_FILE_SIZE_MB)) * 1024;
        $phpUploadLimitKb = $this->iniSizeToKilobytes((string) ini_get('upload_max_filesize'));
        $phpPostLimitKb = $this->iniSizeToKilobytes((string) ini_get('post_max_size'));
        $limits = array_values(array_filter([$appLimitKb, $phpUploadLimitKb, $phpPostLimitKb], fn (int $limit) => $limit > 0));

        return empty($limits) ? $appLimitKb : min($limits);
    }

    protected function iniSizeToKilobytes(string $value): int
    {
        $normalized = trim(strtolower($value));
        if ($normalized === '' || $normalized === '0') {
            return 0;
        }

        $number = (float) $normalized;
        $unit = substr($normalized, -1);

        $bytes = match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => (float) $normalized,
        };

        return (int) floor($bytes / 1024);
    }
}
