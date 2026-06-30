<?php

namespace App\Services;

use App\Mail\TwoFactorCodeMail;
use App\Models\AdminUser;
use App\Models\TwoFactorChallenge;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use RuntimeException;

class TwoFactorService
{
    public function issueChallenge(AdminUser $user, string $userName): TwoFactorChallenge
    {
        $email = Str::lower($user->email);
        $rateLimitKey = $this->rateLimitKey($email);

        if (RateLimiter::tooManyAttempts($rateLimitKey, 1)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            throw new RuntimeException('Espera '.$seconds.' segundos antes de solicitar un nuevo codigo.');
        }

        $code = $this->generateNumericCode();
        $challenge = TwoFactorChallenge::query()->updateOrCreate(
            ['email' => $email],
            [
                'challenge_id' => (string) Str::uuid(),
                'code' => Hash::make($code),
                'is_admin' => $user->role === 'admin',
                'user_name' => $userName,
                'attempts' => 0,
                'max_attempts' => $this->maxAttempts(),
                'sent_at' => Carbon::now(),
                'expires_at' => Carbon::now()->addMinutes($this->expiresMinutes()),
            ]
        );

        try {
            Mail::to($email)->send(new TwoFactorCodeMail(
                appName: config('app.name', 'Tema Litoclean'),
                recipientName: $userName,
                code: $code,
                expiresMinutes: $this->expiresMinutes(),
                supportEmail: (string) config('mail.from.address', 'no-reply@example.com'),
            ));
        } catch (\Throwable $exception) {
            $challenge->delete();

            throw new RuntimeException('No fue posible enviar el correo de doble autenticacion. Revisa la configuracion SMTP.', 0, $exception);
        }

        RateLimiter::hit($rateLimitKey, $this->resendCooldownSeconds());

        return $challenge->fresh();
    }

    public function verifyChallenge(string $challengeId, string $code): array
    {
        $challenge = TwoFactorChallenge::query()->where('challenge_id', $challengeId)->first();
        if (! $challenge || ($challenge->expires_at && $challenge->expires_at->isPast())) {
            if ($challenge) {
                $challenge->delete();
            }

            return ['status' => 'expired'];
        }

        if ($challenge->attempts >= $challenge->max_attempts) {
            $challenge->delete();

            return ['status' => 'locked'];
        }

        if (! Hash::check($code, $challenge->code)) {
            $newAttempts = $challenge->attempts + 1;
            $challenge->forceFill(['attempts' => $newAttempts])->save();

            if ($newAttempts >= $challenge->max_attempts) {
                $challenge->delete();

                return ['status' => 'locked'];
            }

            return ['status' => 'invalid'];
        }

        return ['status' => 'ok', 'challenge' => $challenge];
    }

    protected function generateNumericCode(): string
    {
        $length = max(6, (int) env('TWO_FACTOR_CODE_LENGTH', 6));
        $code = '';

        for ($index = 0; $index < $length; $index++) {
            $code .= (string) random_int(0, 9);
        }

        return $code;
    }

    protected function expiresMinutes(): int
    {
        return max(3, (int) env('TWO_FACTOR_EXPIRES_MINUTES', 10));
    }

    protected function maxAttempts(): int
    {
        return max(3, (int) env('TWO_FACTOR_MAX_ATTEMPTS', 5));
    }

    protected function resendCooldownSeconds(): int
    {
        return max(15, (int) env('TWO_FACTOR_RESEND_COOLDOWN_SECONDS', 60));
    }

    protected function rateLimitKey(string $email): string
    {
        return 'two-factor:send:'.sha1($email);
    }
}
