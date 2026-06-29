<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Services\AppSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $email = Str::lower(trim((string) $request->input('email', '')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['detail' => 'Ingresa un correo válido.'], 400);
        }

        if ($settings['require_corporate_email'] && ! in_array(Str::after($email, '@'), $settings['allowed_domains'], true)) {
            return response()->json(['detail' => 'Solo se permiten usuarios con correo corporativo.'], 403);
        }

        if (AdminUser::query()->where('email', $email)->exists()) {
            return response()->json(['detail' => 'Ya existe un usuario con ese correo.'], 409);
        }

        $user = AdminUser::query()->create([
            'name' => trim((string) $request->input('name', '')),
            'email' => $email,
            'role' => Str::lower(trim((string) $request->input('role', 'analista'))),
            'active' => true,
            'two_factor_enabled' => true,
        ]);

        return response()->json($this->transform($user));
    }

    public function update(string $userId, Request $request, AppSettingsService $appSettings): JsonResponse
    {
        $appSettings->ensureDefaults();
        $user = AdminUser::query()->find($userId);

        if (! $user) {
            return response()->json(['detail' => 'Usuario no encontrado.'], 404);
        }

        if ($request->has('role')) {
            $user->role = Str::lower(trim((string) $request->input('role')));
        }

        if ($request->has('active')) {
            $user->active = (bool) $request->boolean('active');
        }

        if ($request->has('two_factor_enabled')) {
            $user->two_factor_enabled = (bool) $request->boolean('two_factor_enabled');
        }

        $user->save();

        return response()->json($this->transform($user));
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
        ];
    }
}
