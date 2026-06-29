<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class FileArtifactService
{
    public function __construct(
        protected AppSettingsService $appSettings,
    ) {
    }

    public function saveUploads(array $files): array
    {
        $this->ensureDirectories();

        return array_map(fn (UploadedFile $file) => $this->saveUpload($file), $files);
    }

    public function saveUpload(UploadedFile $file): array
    {
        $originalName = $file->getClientOriginalName() ?: 'archivo.bin';
        $safeStem = $this->sanitizeName(pathinfo($originalName, PATHINFO_FILENAME));
        $extension = $file->getClientOriginalExtension();
        $suffix = $extension ? '.'.$extension : '.bin';
        $artifactId = $safeStem.'_'.Str::lower(Str::random(10)).$suffix;
        $destination = $this->uploadsPath().DIRECTORY_SEPARATOR.$artifactId;

        $file->move($this->uploadsPath(), $artifactId);

        return $this->buildArtifact($destination, $originalName);
    }

    public function getFilePath(string $artifactId): ?string
    {
        foreach ([$this->uploadsPath(), $this->outputsPath(), $this->tempPath()] as $folder) {
            $candidate = $folder.DIRECTORY_SEPARATOR.$artifactId;
            if (File::exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function buildArtifact(string $filePath, ?string $displayName = null): array
    {
        return [
            'id' => basename($filePath),
            'name' => $displayName ?: basename($filePath),
            'kind' => $this->inferKind($filePath),
            'size' => File::size($filePath),
            'download_url' => '/api/files/'.basename($filePath),
        ];
    }

    public function writeTextOutput(string $jobId, string $filename, string $contents): array
    {
        $path = $this->prepareOutputPath($jobId, $filename);
        File::put($path, $contents);

        return $this->buildArtifact($path, $filename);
    }

    public function writeBinaryOutput(string $jobId, string $filename, string $contents): array
    {
        $path = $this->prepareOutputPath($jobId, $filename);
        File::put($path, $contents);

        return $this->buildArtifact($path, $filename);
    }

    public function prepareOutputPath(string $jobId, string $filename): string
    {
        $this->ensureDirectories();

        $stem = $this->sanitizeName(pathinfo($filename, PATHINFO_FILENAME));
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $suffix = $extension !== '' ? '.'.$this->sanitizeName($extension) : '';
        $jobPrefix = $this->sanitizeName($jobId);

        return $this->outputsPath().DIRECTORY_SEPARATOR.$jobPrefix.'_'.$stem.$suffix;
    }

    public function ensureDirectories(): void
    {
        foreach ([$this->storageRoot(), $this->uploadsPath(), $this->outputsPath(), $this->tempPath()] as $path) {
            File::ensureDirectoryExists($path);
        }
    }

    protected function storageRoot(): string
    {
        return $this->appSettings->storageRootPath();
    }

    protected function uploadsPath(): string
    {
        return $this->storageRoot().DIRECTORY_SEPARATOR.'uploads';
    }

    protected function outputsPath(): string
    {
        return $this->storageRoot().DIRECTORY_SEPARATOR.'outputs';
    }

    protected function tempPath(): string
    {
        return $this->storageRoot().DIRECTORY_SEPARATOR.'temp';
    }

    protected function sanitizeName(string $value): string
    {
        $cleaned = preg_replace('/[^A-Za-z0-9._-]+/', '_', $value) ?? 'artifact';
        $cleaned = trim($cleaned, '._');

        return $cleaned !== '' ? $cleaned : 'artifact';
    }

    protected function inferKind(string $filePath): string
    {
        $extension = Str::lower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => 'pdf',
            'docx', 'pptx', 'xlsx' => 'office',
            'png', 'jpg', 'jpeg', 'svg', 'webp' => 'image',
            'json' => 'json',
            'md', 'txt' => 'text',
            default => 'binary',
        };
    }
}
