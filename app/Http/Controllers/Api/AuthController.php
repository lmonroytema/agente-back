<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\PasswordResetChallenge;
use App\Services\AppSettingsService;
use App\Services\PasswordResetService;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class AuthController extends Controller
{
    public function policy(AppSettingsService $appSettings): JsonResponse
    {
        $settings = $appSettings->toPayload($appSettings->ensureDefaults());
        $domainHint = ! empty($settings['allowed_domains'])
            ? implode(', ', array_map(fn ($domain) => '@'.$domain, $settings['allowed_domains']))
            : 'correo corporativo';

        return response()->json([
            'company_name' => $settings['company_name'],
            'require_corporate_email' => $settings['require_corporate_email'],
            'allowed_domains' => $settings['allowed_domains'],
            'identity_provider' => $settings['corporate_identity_provider'],
            'login_message' => "Acceso restringido a usuarios corporativos con correo {$domainHint}.",
            'require_two_factor' => $settings['require_two_factor'],
        ]);
    }

    public function login(Request $request, AppSettingsService $appSettings, TwoFactorService $twoFactor): JsonResponse
    {
        $settings = $appSettings->toPayload($appSettings->ensureDefaults());
        $email = Str::lower(trim((string) $request->input('email', '')));
        $password = (string) $request->input('password', '');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['detail' => 'Ingresa un correo válido.'], 400);
        }

        if (mb_strlen($password) < 8) {
            return response()->json(['detail' => 'Ingresa una contraseña válida de al menos 8 caracteres.'], 400);
        }

        if ($settings['require_corporate_email'] && ! $this->isAllowedCorporateEmail($email, $settings['allowed_domains'])) {
            return response()->json(['detail' => 'Solo se permiten correos corporativos autorizados.'], 403);
        }

        $user = AdminUser::query()->where('email', $email)->first();
        if (! $user) {
            return response()->json(['detail' => 'El usuario no existe en el padrón corporativo habilitado.'], 403);
        }

        if (! $user->active) {
            return response()->json(['detail' => 'El usuario corporativo está inactivo.'], 403);
        }

        if (! filled($user->password_hash) || ! Hash::check($password, (string) $user->password_hash)) {
            return response()->json(['detail' => 'Las credenciales ingresadas no son válidas.'], 401);
        }

        $userName = filled($user->name) ? $user->name : $this->buildUserName($email);

        if ($settings['require_two_factor'] && $user->two_factor_enabled) {
            try {
                $challenge = $twoFactor->issueChallenge($user, $userName);
            } catch (RuntimeException $exception) {
                $status = str_contains($exception->getMessage(), 'Espera ') ? 429 : 502;

                return response()->json(['detail' => $exception->getMessage()], $status);
            }

            return response()->json([
                'success' => true,
                'message' => 'Credenciales validadas. Hemos enviado un codigo de doble autenticacion a tu correo corporativo.',
                'email' => $email,
                'domain' => Str::after($email, '@'),
                'user_name' => $userName,
                'is_admin' => $user->role === 'admin',
                'requires_two_factor' => true,
                'challenge_id' => $challenge->challenge_id,
                'masked_destination' => $this->maskEmail($email),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Validación corporativa superada.',
            'email' => $email,
            'domain' => Str::after($email, '@'),
            'user_name' => $userName,
            'session_token' => Str::random(48),
            'is_admin' => $user->role === 'admin',
            'requires_two_factor' => false,
        ]);
    }

    public function forgotPassword(Request $request, AppSettingsService $appSettings, PasswordResetService $passwordReset): JsonResponse
    {
        $settings = $appSettings->toPayload($appSettings->ensureDefaults());
        $email = Str::lower(trim((string) $request->input('email', '')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['detail' => 'Ingresa un correo válido.'], 400);
        }

        if ($settings['require_corporate_email'] && ! $this->isAllowedCorporateEmail($email, $settings['allowed_domains'])) {
            return response()->json([
                'success' => true,
                'message' => 'Si el correo está habilitado, enviaremos un enlace seguro para restablecer la contraseña.',
            ]);
        }

        $user = AdminUser::query()->where('email', $email)->first();
        if (! $user || ! $user->active) {
            return response()->json([
                'success' => true,
                'message' => 'Si el correo está habilitado, enviaremos un enlace seguro para restablecer la contraseña.',
            ]);
        }

        $userName = filled($user->name) ? $user->name : $this->buildUserName($email);

        try {
            $passwordReset->issueChallenge($user, $userName);
        } catch (RuntimeException $exception) {
            $status = str_contains($exception->getMessage(), 'Espera ') ? 429 : 502;

            return response()->json(['detail' => $exception->getMessage()], $status);
        }

        return response()->json([
            'success' => true,
            'message' => 'Si el correo está habilitado, enviaremos un enlace seguro para restablecer la contraseña.',
        ]);
    }

    public function resetPassword(Request $request, PasswordResetService $passwordReset): JsonResponse
    {
        $validated = $request->validate([
            'reset_id' => ['required', 'string'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $verification = $passwordReset->verifyChallenge(
            (string) $validated['reset_id'],
            (string) $validated['token'],
        );

        if ($verification['status'] !== 'ok') {
            return response()->json([
                'detail' => 'El enlace de recuperación ya no es válido o expiró. Solicita uno nuevo.',
            ], 404);
        }

        /** @var PasswordResetChallenge $challenge */
        $challenge = $verification['challenge'];
        $user = AdminUser::query()->where('email', $challenge->email)->first();

        if (! $user || ! $user->active) {
            $challenge->delete();

            return response()->json([
                'detail' => 'El enlace de recuperación ya no es válido o expiró. Solicita uno nuevo.',
            ], 404);
        }

        $user->password_hash = Hash::make((string) $validated['password']);
        $user->password_changed_at = now();
        $user->save();

        PasswordResetChallenge::query()->where('email', $challenge->email)->delete();

        return response()->json([
            'success' => true,
            'message' => 'La contraseña fue actualizada correctamente. Ya puedes iniciar sesión con la nueva clave.',
        ]);
    }

    public function resendTwoFactor(Request $request, AppSettingsService $appSettings, TwoFactorService $twoFactor): JsonResponse
    {
        $settings = $appSettings->toPayload($appSettings->ensureDefaults());
        $challengeId = trim((string) $request->input('challenge_id', ''));

        $challenge = \App\Models\TwoFactorChallenge::query()->where('challenge_id', $challengeId)->first();
        if (! $challenge) {
            return response()->json(['detail' => 'El desafío de doble autenticación ya no es válido.'], 404);
        }

        $user = AdminUser::query()->where('email', $challenge->email)->first();
        if (! $user || ! $user->active || ! $settings['require_two_factor'] || ! $user->two_factor_enabled) {
            $challenge->delete();

            return response()->json(['detail' => 'La doble autenticación ya no está habilitada para este usuario.'], 422);
        }

        try {
            $newChallenge = $twoFactor->issueChallenge($user, $challenge->user_name ?: $user->name);
        } catch (RuntimeException $exception) {
            $status = str_contains($exception->getMessage(), 'Espera ') ? 429 : 502;

            return response()->json(['detail' => $exception->getMessage()], $status);
        }

        return response()->json([
            'success' => true,
            'message' => 'Se envio un nuevo codigo de doble autenticacion al correo corporativo.',
            'challenge_id' => $newChallenge->challenge_id,
            'masked_destination' => $this->maskEmail($challenge->email),
        ]);
    }

    public function verifyTwoFactor(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        $challengeId = trim((string) $request->input('challenge_id', ''));
        $code = trim((string) $request->input('code', ''));
        $verification = $twoFactor->verifyChallenge($challengeId, $code);

        if ($verification['status'] === 'expired') {
            return response()->json(['detail' => 'El desafío de doble autenticación ya no es válido o expiró.'], 404);
        }

        if ($verification['status'] === 'locked') {
            return response()->json(['detail' => 'El codigo excedio el maximo de intentos permitidos. Solicita uno nuevo.'], 423);
        }

        if ($verification['status'] === 'invalid') {
            return response()->json(['detail' => 'El codigo de doble autenticacion es incorrecto.'], 401);
        }

        /** @var \App\Models\TwoFactorChallenge $challenge */
        $challenge = $verification['challenge'];

        $email = $challenge->email;
        $payload = [
            'success' => true,
            'message' => 'Doble autenticación validada correctamente.',
            'email' => $email,
            'domain' => Str::after($email, '@'),
            'user_name' => $challenge->user_name,
            'session_token' => Str::random(48),
            'is_admin' => (bool) $challenge->is_admin,
            'requires_two_factor' => false,
        ];

        $challenge->delete();

        return response()->json($payload);
    }

    protected function isAllowedCorporateEmail(string $email, array $allowedDomains): bool
    {
        $domain = Str::after($email, '@');

        return in_array(Str::lower($domain), $allowedDomains, true);
    }

    protected function buildUserName(string $email): string
    {
        $localPart = Str::before($email, '@');
        $pieces = preg_split('/[._-]+/', $localPart) ?: [$localPart];
        $pieces = array_filter($pieces, fn ($piece) => trim((string) $piece) !== '');

        return empty($pieces)
            ? $email
            : implode(' ', array_map(fn ($piece) => Str::title((string) $piece), $pieces));
    }

    protected function maskEmail(string $email): string
    {
        $localPart = Str::before($email, '@');
        $domain = Str::after($email, '@');

        if (mb_strlen($localPart) <= 2) {
            $masked = mb_substr($localPart, 0, 1).'*';
        } else {
            $masked = mb_substr($localPart, 0, 2).str_repeat('*', max(2, mb_strlen($localPart) - 2));
        }

        return "{$masked}@{$domain}";
    }
}
