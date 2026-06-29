<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reusable, gated client for the Anthropic Messages API.
 *
 * Centralizes model id, retry policy and the enable/credentials gate so every
 * caller (PDF analysis, report narrative) shares one implementation instead of
 * duplicating retry logic. AI is ONLY used for narrative; callers must provide a
 * deterministic fallback when this returns no text.
 */
class AnthropicClient
{
    public const MODEL = 'claude-sonnet-4-5';

    protected const MAX_ATTEMPTS = 3;

    public function __construct(
        protected AppSettingsService $appSettings,
    ) {
    }

    /**
     * True when routing is enabled and an API key is configured.
     */
    public function isEnabled(): bool
    {
        $settings = $this->appSettings->ensureDefaults();

        return (bool) $settings->enable_anthropic_routing && filled($settings->anthropic_api_key);
    }

    /**
     * Sends a single-turn prompt and returns [text, error] with bounded retries.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function complete(string $prompt, int $maxTokens = 1200, ?string $jobId = null): array
    {
        $settings = $this->appSettings->ensureDefaults();
        if (! $settings->enable_anthropic_routing || ! $settings->anthropic_api_key) {
            return [null, 'enrutamiento a Anthropic deshabilitado o sin API key configurada.'];
        }

        $lastError = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = Http::timeout(60)
                    ->withHeaders([
                        'x-api-key' => $settings->anthropic_api_key,
                        'anthropic-version' => '2023-06-01',
                        'content-type' => 'application/json',
                    ])
                    ->post('https://api.anthropic.com/v1/messages', [
                        'model' => self::MODEL,
                        'max_tokens' => $maxTokens,
                        'messages' => [
                            ['role' => 'user', 'content' => $prompt],
                        ],
                    ]);

                if ($response->successful()) {
                    $payload = $response->json();
                    $content = $payload['content'][0]['text'] ?? null;
                    Log::info('Anthropic call succeeded', [
                        'job_id' => $jobId,
                        'model' => self::MODEL,
                        'attempt' => $attempt,
                        'input_tokens' => $payload['usage']['input_tokens'] ?? null,
                        'output_tokens' => $payload['usage']['output_tokens'] ?? null,
                    ]);

                    return is_string($content) ? [$content, null] : [null, 'respuesta del modelo sin contenido de texto.'];
                }

                $status = $response->status();
                $lastError = 'Anthropic respondio con HTTP '.$status.'.';
                if ($status !== 429 && $status < 500) {
                    break;
                }
            } catch (Throwable $exception) {
                $lastError = 'Excepcion al llamar a Anthropic: '.$exception->getMessage();
            }

            if ($attempt < self::MAX_ATTEMPTS) {
                usleep((int) (250000 * $attempt));
            }
        }

        Log::warning('Anthropic call failed after retries', [
            'job_id' => $jobId,
            'attempts' => self::MAX_ATTEMPTS,
            'error' => $lastError,
        ]);

        return [null, $lastError];
    }
}
