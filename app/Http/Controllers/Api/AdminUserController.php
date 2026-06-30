<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Services\AppSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class AdminUserController extends Controller
{
    public function index(AppSettingsService $appSettings): JsonResponse
    {
        $appSettings->ensureDefaults();

        return response()->json(
            AdminUser::query()->orderBy('created_at')->get()->map(fn (AdminUser $user) => $this->transform($user))->all()
        );
    }

    public function store(Request $request, AppSettingsService $appSettings): JsonResponse
    {
        $settings = $appSettings->toPayload($appSettings->ensureDefaults());
        $payload = $this->validatedPayload($request, $settings);
        $email = $payload['email'];

        if ($settings['require_corporate_email'] && ! in_array(Str::after($email, '@'), $settings['allowed_domains'], true)) {
            return response()->json(['detail' => 'Solo se permiten usuarios con correo corporativo.'], 403);
        }

        $user = AdminUser::query()->create([
            'name' => $payload['name'],
            'email' => $email,
            'role' => $payload['role'],
            'active' => $payload['active'],
            'two_factor_enabled' => $payload['two_factor_enabled'],
        ]);

        return response()->json($this->transform($user));
    }

    public function update(string $userId, Request $request, AppSettingsService $appSettings): JsonResponse
    {
        $settings = $appSettings->toPayload($appSettings->ensureDefaults());
        $user = AdminUser::query()->find($userId);

        if (! $user) {
            return response()->json(['detail' => 'Usuario no encontrado.'], 404);
        }

        $payload = $this->validatedPayload($request, $settings, $user);

        if ($settings['require_corporate_email'] && ! in_array(Str::after($payload['email'], '@'), $settings['allowed_domains'], true)) {
            return response()->json(['detail' => 'Solo se permiten usuarios con correo corporativo.'], 403);
        }

        $newAttributes = [
            'name' => $payload['name'],
            'email' => $payload['email'],
            'role' => $payload['role'],
            'active' => $payload['active'],
            'two_factor_enabled' => $payload['two_factor_enabled'],
        ];

        if ($this->wouldLeaveNoActiveAdmin($user, $newAttributes)) {
            return response()->json([
                'detail' => 'Debe permanecer al menos un administrador activo con acceso habilitado.',
            ], 422);
        }

        foreach ($newAttributes as $field => $value) {
            $user->{$field} = $value;
        }

        $user->save();

        return response()->json($this->transform($user));
    }

    public function destroy(string $userId, AppSettingsService $appSettings): JsonResponse
    {
        $appSettings->ensureDefaults();
        $user = AdminUser::query()->find($userId);

        if (! $user) {
            return response()->json(['detail' => 'Usuario no encontrado.'], 404);
        }

        if ($this->wouldLeaveNoActiveAdmin($user, [
            'role' => 'analista',
            'active' => false,
            'two_factor_enabled' => false,
        ])) {
            return response()->json([
                'detail' => 'No se puede eliminar al ultimo administrador activo del sistema.',
            ], 422);
        }

        $user->delete();

        return response()->json(['success' => true]);
    }

    protected function validatedPayload(Request $request, array $settings, ?AdminUser $user = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required',
                'email',
                'max:190',
                Rule::unique('admin_users', 'email')->ignore($user?->id),
            ],
            'role' => ['required', Rule::in(['analista', 'operaciones', 'auditoria', 'admin'])],
            'active' => ['sometimes', 'boolean'],
            'two_factor_enabled' => ['sometimes', 'boolean'],
        ]);

        return [
            'name' => trim((string) $validated['name']),
            'email' => Str::lower(trim((string) $validated['email'])),
            'role' => Str::lower(trim((string) $validated['role'])),
            'active' => array_key_exists('active', $validated) ? (bool) $validated['active'] : (bool) ($user?->active ?? true),
            'two_factor_enabled' => array_key_exists('two_factor_enabled', $validated)
                ? (bool) $validated['two_factor_enabled']
                : (bool) ($user?->two_factor_enabled ?? ($settings['require_two_factor'] ?? true)),
        ];
    }

    protected function wouldLeaveNoActiveAdmin(AdminUser $user, array $newAttributes): bool
    {
        $willRemainAdmin = ($newAttributes['role'] ?? $user->role) === 'admin';
        $willRemainActive = (bool) ($newAttributes['active'] ?? $user->active);

        if ($willRemainAdmin && $willRemainActive) {
            return false;
        }

        return AdminUser::query()
            ->where('id', '!=', $user->id)
            ->where('role', 'admin')
            ->where('active', true)
            ->doesntExist();
    }

    protected function transform(AdminUser $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'active' => (bool) $user->active,
            'two_factor_enabled' => (bool) $user->two_factor_enabled,
            'created_at' => optional($user->created_at)?->toIso8601String(),
            'updated_at' => optional($user->updated_at)?->toIso8601String(),
        ];
    }
}
