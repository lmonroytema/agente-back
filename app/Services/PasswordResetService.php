<?php

namespace App\Services;

use App\Mail\PasswordResetLinkMail;
use App\Models\AdminUser;
use App\Models\PasswordResetChallenge;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use RuntimeException;

class PasswordResetService
{
    public function issueChallenge(AdminUser $user, string $userName): PasswordResetChallenge
    {
        $email = Str::lower($user->email);
        $rateLimitKey = $this->rateLimitKey($email);

        if (RateLimiter::tooManyAttempts($rateLimitKey, 1)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            throw new RuntimeException('Espera '.$seconds.' segundos antes de solicitar un nuevo enlace de recuperación.');
        }

        $token = bin2hex(random_bytes(32));
        $challenge = PasswordResetChallenge::query()->updateOrCreate(
            ['email' => $email],
            [
                'reset_id' => (string) Str::uuid(),
                'token_hash' => Hash::make($token),
                'user_name' => $userName,
                'sent_at' => Carbon::now(),
                'expires_at' => Carbon::now()->addMinutes($this->expiresMinutes()),
            ]
        );

        $resetUrl = $this->buildResetUrl($challenge->reset_id, $token);

        try {
            Mail::to($email)->send(new PasswordResetLinkMail(
                appName: config('app.name', 'Tema Litoclean'),
                recipientName: $userName,
                resetUrl: $resetUrl,
                expiresMinutes: $this->expiresMinutes(),
                supportEmail: (string) config('mail.from.address', 'no-reply@example.com'),
            ));
        } catch (\Throwable $exception) {
            $challenge->delete();

            throw new RuntimeException('No fue posible enviar el correo de recuperación de contraseña. Revisa la configuración SMTP.', 0, $exception);
        }

        RateLimiter::hit($rateLimitKey, $this->resendCooldownSeconds());

        return $challenge->fresh();
    }

    public function verifyChallenge(string $resetId, string $token): array
    {
        $challenge = PasswordResetChallenge::query()->where('reset_id', $resetId)->first();
        if (! $challenge || ($challenge->expires_at && $challenge->expires_at->isPast())) {
            if ($challenge) {
                $challenge->delete();
            }

            return ['status' => 'expired'];
        }

        if (! Hash::check($token, $challenge->token_hash)) {
            return ['status' => 'invalid'];
        }

        return ['status' => 'ok', 'challenge' => $challenge];
    }

    protected function buildResetUrl(string $resetId, string $token): string
    {
        $appUrl = rtrim((string) config('app.url', 'http://localhost'), '/');

        return $appUrl.'/?reset_id='.rawurlencode($resetId).'&token='.rawurlencode($token);
    }

    protected function expiresMinutes(): int
    {
        return max(10, (int) env('PASSWORD_RESET_EXPIRES_MINUTES', 30));
    }

    protected function resendCooldownSeconds(): int
    {
        return max(15, (int) env('PASSWORD_RESET_RESEND_COOLDOWN_SECONDS', 60));
    }

    protected function rateLimitKey(string $email): string
    {
        return 'password-reset:send:'.sha1($email);
    }
}
