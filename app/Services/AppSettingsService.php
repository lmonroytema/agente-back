<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\AppSetting;
use App\Models\CorporateEndpoint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class AppSettingsService
{
    public function ensureDefaults(): AppSetting
    {
        $defaults = $this->legacyBackedDefaults();

        $setting = AppSetting::query()->with('corporateEndpoints')->first();
        if (! $setting) {
            $setting = AppSetting::query()->create($this->extractSettingAttributes($defaults));
            $this->syncEndpoints($setting, $defaults['corporate_endpoints'] ?? []);
        } elseif ($setting->corporateEndpoints->isEmpty() && ! empty($defaults['corporate_endpoints'])) {
            $this->syncEndpoints($setting, $defaults['corporate_endpoints']);
            $setting->load('corporateEndpoints');
        }

        if (AdminUser::query()->count() === 0) {
            foreach ($this->defaultAdminUsers() as $user) {
                AdminUser::query()->create($user);
            }
        }

        if ($this->synchronizeManagedPaths($setting)) {
            $setting->refresh();
        }

        $setting->load('corporateEndpoints');
        $this->syncLegacyJson($setting);

        return $setting;
    }

    public function updateSettings(array $payload): AppSetting
    {
        $setting = $this->ensureDefaults();
        $endpoints = $payload['corporate_endpoints'] ?? null;
        unset($payload['corporate_endpoints']);

        $setting->fill($this->extractSettingAttributes($payload));
        $setting->save();

        if (is_array($endpoints)) {
            $this->syncEndpoints($setting, $endpoints);
        }

        $setting->refresh()->load('corporateEndpoints');
        $this->syncLegacyJson($setting);

        return $setting;
    }

    public function toPayload(AppSetting $setting): array
    {
        $setting->loadMissing('corporateEndpoints');

        return [
            'app_name' => $setting->app_name,
            'company_name' => $setting->company_name,
            'company_industry' => $setting->company_industry,
            'preferred_surface' => $setting->preferred_surface,
            'deployment_target' => $setting->deployment_target,
            'corporate_identity_provider' => $setting->corporate_identity_provider,
            'require_corporate_email' => (bool) $setting->require_corporate_email,
            'allowed_domains' => $this->normalizeDomains($setting->allowed_domains ?? []),
            'require_two_factor' => (bool) $setting->require_two_factor,
            'corporate_api_base_url' => $setting->corporate_api_base_url,
            'corporate_api_auth_method' => $setting->corporate_api_auth_method,
            'corporate_api_key_endpoints' => $setting->corporate_api_key_endpoints,
            'data_restrictions' => $setting->data_restrictions,
            'pii_policy' => $setting->pii_policy,
            'retention_policy' => $setting->retention_policy,
            'approved_gen_ai_provider' => $setting->approved_gen_ai_provider,
            'anthropic_api_key' => $setting->anthropic_api_key,
            'enable_anthropic_routing' => (bool) $setting->enable_anthropic_routing,
            'gen_image_api_provider' => $setting->gen_image_api_provider,
            'gen_video_api_provider' => $setting->gen_video_api_provider,
            'gen_api_budget_monthly' => $setting->gen_api_budget_monthly,
            'gen_api_budget_alert_threshold' => $setting->gen_api_budget_alert_threshold,
            'office_graph_tenant_id' => $setting->office_graph_tenant_id,
            'office_graph_client_id' => $setting->office_graph_client_id,
            'office_graph_client_secret' => $setting->office_graph_client_secret,
            'admin_user_emails' => $this->normalizeEmails($setting->admin_user_emails ?? []),
            'allowed_origins' => $this->normalizeSimpleList($setting->allowed_origins ?? []),
            'storage_dir' => $this->normalizeManagedDirectory($setting->storage_dir, $this->defaultStorageDir()),
            'audit_dir' => $this->normalizeManagedDirectory($setting->audit_dir, $this->defaultAuditDir()),
            'mock_corporate_data' => (bool) $setting->mock_corporate_data,
            'phase1_priority_use_cases' => $setting->phase1_priority_use_cases,
            'observability_notes' => $setting->observability_notes,
            'corporate_endpoints' => $setting->corporateEndpoints
                ->sortBy('created_at')
                ->values()
                ->map(fn (CorporateEndpoint $endpoint) => [
                    'id' => $endpoint->endpoint_id,
                    'name' => $endpoint->name,
                    'base_url' => $endpoint->base_url,
                    'auth_method' => $endpoint->auth_method,
                    'owner' => $endpoint->owner,
                    'pii_scope' => $endpoint->pii_scope,
                    'enabled' => (bool) $endpoint->enabled,
                ])
                ->all(),
        ];
    }

    public function syncLegacyJson(AppSetting $setting): void
    {
        $payload = $this->toPayload($setting);
        $filePath = $this->legacySettingsFilePath();
        File::ensureDirectoryExists(dirname($filePath));
        File::put(
            $filePath,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    public function projectRoot(): string
    {
        return dirname(base_path());
    }

    public function storageRootPath(): string
    {
        $setting = $this->ensureDefaults();

        return $this->resolveManagedPath((string) $setting->storage_dir, $this->defaultStorageDir());
    }

    public function auditRootPath(): string
    {
        $setting = $this->ensureDefaults();

        return $this->resolveManagedPath((string) $setting->audit_dir, $this->defaultAuditDir());
    }

    public function normalizeDomains(array $domains): array
    {
        $normalized = array_map(
            fn ($domain) => ltrim(Str::lower(trim((string) $domain)), '@'),
            $domains
        );

        return array_values(array_filter(array_unique($normalized)));
    }

    public function normalizeEmails(array $emails): array
    {
        $normalized = array_map(
            fn ($email) => Str::lower(trim((string) $email)),
            $emails
        );

        return array_values(array_filter(array_unique($normalized)));
    }

    public function normalizeSimpleList(array $values): array
    {
        $normalized = array_map(
            fn ($value) => trim((string) $value),
            $values
        );

        return array_values(array_filter($normalized, fn ($value) => $value !== ''));
    }

    public function cleanOptionalText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $cleaned = trim((string) $value);

        return $cleaned === '' ? null : $cleaned;
    }

    protected function extractSettingAttributes(array $payload): array
    {
        $defaults = $this->defaultSettings();
        $current = array_merge($defaults, $payload);

        return [
            'singleton_key' => 'default',
            'app_name' => $this->cleanOptionalText($current['app_name']) ?? $defaults['app_name'],
            'company_name' => $this->cleanOptionalText($current['company_name']) ?? $defaults['company_name'],
            'company_industry' => $this->cleanOptionalText($current['company_industry']) ?? $defaults['company_industry'],
            'preferred_surface' => $this->cleanOptionalText($current['preferred_surface']) ?? $defaults['preferred_surface'],
            'deployment_target' => $this->cleanOptionalText($current['deployment_target']) ?? $defaults['deployment_target'],
            'corporate_identity_provider' => $this->cleanOptionalText($current['corporate_identity_provider']) ?? $defaults['corporate_identity_provider'],
            'require_corporate_email' => (bool) ($current['require_corporate_email'] ?? true),
            'allowed_domains' => $this->normalizeDomains($current['allowed_domains'] ?? $defaults['allowed_domains']),
            'require_two_factor' => (bool) ($current['require_two_factor'] ?? true),
            'corporate_api_base_url' => $this->cleanOptionalText($current['corporate_api_base_url'] ?? null),
            'corporate_api_auth_method' => $this->cleanOptionalText($current['corporate_api_auth_method'] ?? null),
            'corporate_api_key_endpoints' => $this->cleanOptionalText($current['corporate_api_key_endpoints'] ?? null),
            'data_restrictions' => $this->cleanOptionalText($current['data_restrictions'] ?? null),
            'pii_policy' => $this->cleanOptionalText($current['pii_policy'] ?? null),
            'retention_policy' => $this->cleanOptionalText($current['retention_policy'] ?? null),
            'approved_gen_ai_provider' => $this->cleanOptionalText($current['approved_gen_ai_provider'] ?? null),
            'anthropic_api_key' => $this->cleanOptionalText($current['anthropic_api_key'] ?? null),
            'enable_anthropic_routing' => (bool) ($current['enable_anthropic_routing'] ?? false),
            'gen_image_api_provider' => $this->cleanOptionalText($current['gen_image_api_provider'] ?? null),
            'gen_video_api_provider' => $this->cleanOptionalText($current['gen_video_api_provider'] ?? null),
            'gen_api_budget_monthly' => $this->cleanOptionalText($current['gen_api_budget_monthly'] ?? null),
            'gen_api_budget_alert_threshold' => $this->cleanOptionalText($current['gen_api_budget_alert_threshold'] ?? null),
            'office_graph_tenant_id' => $this->cleanOptionalText($current['office_graph_tenant_id'] ?? null),
            'office_graph_client_id' => $this->cleanOptionalText($current['office_graph_client_id'] ?? null),
            'office_graph_client_secret' => $this->cleanOptionalText($current['office_graph_client_secret'] ?? null),
            'admin_user_emails' => $this->normalizeEmails($current['admin_user_emails'] ?? $defaults['admin_user_emails']),
            'allowed_origins' => $this->normalizeSimpleList($current['allowed_origins'] ?? $defaults['allowed_origins']),
            'storage_dir' => $this->normalizeManagedDirectory($current['storage_dir'] ?? null, $defaults['storage_dir']),
            'audit_dir' => $this->normalizeManagedDirectory($current['audit_dir'] ?? null, $defaults['audit_dir']),
            'mock_corporate_data' => (bool) ($current['mock_corporate_data'] ?? true),
            'phase1_priority_use_cases' => $this->cleanOptionalText($current['phase1_priority_use_cases'] ?? null) ?? $defaults['phase1_priority_use_cases'],
            'observability_notes' => $this->cleanOptionalText($current['observability_notes'] ?? null),
        ];
    }

    protected function syncEndpoints(AppSetting $setting, array $endpoints): void
    {
        $normalized = collect($endpoints)
            ->map(function ($endpoint) {
                $name = trim((string) ($endpoint['name'] ?? ''));
                if ($name === '') {
                    return null;
                }

                $endpointId = trim((string) ($endpoint['id'] ?? ''));
                if ($endpointId === '') {
                    $endpointId = 'endpoint-'.Str::lower(Str::random(8));
                }

                return [
                    'endpoint_id' => $endpointId,
                    'name' => $name,
                    'base_url' => $this->cleanOptionalText($endpoint['base_url'] ?? null),
                    'auth_method' => $this->cleanOptionalText($endpoint['auth_method'] ?? null),
                    'owner' => $this->cleanOptionalText($endpoint['owner'] ?? null),
                    'pii_scope' => $this->cleanOptionalText($endpoint['pii_scope'] ?? null),
                    'enabled' => (bool) ($endpoint['enabled'] ?? true),
                ];
            })
            ->filter()
            ->values();

        $keepIds = $normalized->pluck('endpoint_id')->all();

        $setting->corporateEndpoints()
            ->whereNotIn('endpoint_id', $keepIds ?: ['__empty__'])
            ->delete();

        foreach ($normalized as $endpoint) {
            $setting->corporateEndpoints()->updateOrCreate(
                ['endpoint_id' => $endpoint['endpoint_id']],
                $endpoint
            );
        }
    }

    protected function legacyBackedDefaults(): array
    {
        $defaults = $this->defaultSettings();
        $filePath = collect($this->legacyCandidateSettingsFilePaths())
            ->first(fn (string $candidate) => File::exists($candidate));

        if (! $filePath) {
            return $defaults;
        }

        $decoded = json_decode((string) File::get($filePath), true);
        if (! is_array($decoded)) {
            return $defaults;
        }

        $merged = array_merge($defaults, $decoded);
        $merged['allowed_domains'] = $this->normalizeDomains($merged['allowed_domains'] ?? $defaults['allowed_domains']);
        $merged['admin_user_emails'] = $this->normalizeEmails($merged['admin_user_emails'] ?? $defaults['admin_user_emails']);
        $merged['allowed_origins'] = $this->normalizeSimpleList($merged['allowed_origins'] ?? $defaults['allowed_origins']);
        $merged['storage_dir'] = $this->normalizeManagedDirectory($merged['storage_dir'] ?? null, $defaults['storage_dir']);
        $merged['audit_dir'] = $this->normalizeManagedDirectory($merged['audit_dir'] ?? null, $defaults['audit_dir']);
        $merged['corporate_endpoints'] = is_array($merged['corporate_endpoints'] ?? null) ? $merged['corporate_endpoints'] : [];

        return $merged;
    }

    protected function defaultSettings(): array
    {
        return [
            'app_name' => 'Tema Litoclean',
            'company_name' => 'Tema Litoclean',
            'company_industry' => 'Medioambiental',
            'preferred_surface' => 'Web',
            'deployment_target' => 'Cloud',
            'corporate_identity_provider' => 'Validación por dominio corporativo (preparado para Entra ID)',
            'require_corporate_email' => true,
            'allowed_domains' => ['tema.com.pe', 'tema.es'],
            'require_two_factor' => true,
            'corporate_api_base_url' => null,
            'corporate_api_auth_method' => null,
            'corporate_api_key_endpoints' => null,
            'data_restrictions' => null,
            'pii_policy' => 'No enviar PII sensible a servicios no aprobados.',
            'retention_policy' => 'Mantener auditoría y trazabilidad por job_id.',
            'approved_gen_ai_provider' => null,
            'anthropic_api_key' => null,
            'enable_anthropic_routing' => false,
            'gen_image_api_provider' => null,
            'gen_video_api_provider' => null,
            'gen_api_budget_monthly' => null,
            'gen_api_budget_alert_threshold' => '80%',
            'office_graph_tenant_id' => null,
            'office_graph_client_id' => null,
            'office_graph_client_secret' => null,
            'admin_user_emails' => ['admin@tema.com.pe', 'seguridad@tema.es'],
            'allowed_origins' => ['http://localhost:5173'],
            'storage_dir' => $this->defaultStorageDir(),
            'audit_dir' => $this->defaultAuditDir(),
            'mock_corporate_data' => true,
            'phase1_priority_use_cases' => 'Consulta de servicios, procesamiento PDF, generacion de reportes',
            'observability_notes' => 'Registrar auditoría, responsables y aprobaciones humanas.',
            'corporate_endpoints' => [],
        ];
    }

    protected function defaultAdminUsers(): array
    {
        return [
            [
                'id' => 'usr-admin-001',
                'name' => 'Administrador Tema',
                'email' => 'admin@tema.com.pe',
                'role' => 'admin',
                'active' => true,
                'two_factor_enabled' => true,
            ],
            [
                'id' => 'usr-ops-001',
                'name' => 'Operaciones Tema',
                'email' => 'operaciones@tema.com.pe',
                'role' => 'operaciones',
                'active' => true,
                'two_factor_enabled' => true,
            ],
            [
                'id' => 'usr-audit-001',
                'name' => 'Auditoría Tema',
                'email' => 'seguridad@tema.es',
                'role' => 'auditoria',
                'active' => true,
                'two_factor_enabled' => true,
            ],
        ];
    }

    protected function legacySettingsFilePath(): string
    {
        return $this->resolveManagedPath($this->defaultSettingsJsonPath(), $this->defaultSettingsJsonPath());
    }

    protected function resolveManagedPath(string $path, string $default): string
    {
        $normalized = $this->normalizeManagedDirectory($path, $default);

        if (preg_match('/^[A-Za-z]:\\\\/', $normalized) === 1 || str_starts_with($normalized, '/')) {
            return $normalized;
        }

        return base_path(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $normalized));
    }

    protected function synchronizeManagedPaths(AppSetting $setting): bool
    {
        $storageDir = $this->normalizeManagedDirectory($setting->storage_dir, $this->defaultStorageDir());
        $auditDir = $this->normalizeManagedDirectory($setting->audit_dir, $this->defaultAuditDir());
        $dirty = false;

        if ($setting->storage_dir !== $storageDir) {
            $setting->storage_dir = $storageDir;
            $dirty = true;
        }

        if ($setting->audit_dir !== $auditDir) {
            $setting->audit_dir = $auditDir;
            $dirty = true;
        }

        if ($dirty) {
            $setting->save();
        }

        return $dirty;
    }

    protected function normalizeManagedDirectory(mixed $path, string $default): string
    {
        $cleaned = $this->cleanOptionalText($path);
        if ($cleaned === null) {
            return $default;
        }

        $normalized = trim(str_replace('\\', '/', $cleaned), '/');
        if (in_array($normalized, $this->legacyManagedPaths(), true)) {
            return $default;
        }

        return $normalized;
    }

    protected function legacyManagedPaths(): array
    {
        return [
            'backend/storage',
            'backend/storage/audit',
        ];
    }

    protected function defaultStorageDir(): string
    {
        return trim((string) env('APP_STORAGE_DIR', 'storage/app/tema_litoclean'), '/');
    }

    protected function defaultAuditDir(): string
    {
        return trim((string) env('APP_AUDIT_DIR', 'storage/app/tema_litoclean/audit'), '/');
    }

    protected function defaultSettingsJsonPath(): string
    {
        return trim((string) env('APP_SETTINGS_JSON', 'storage/app/tema_litoclean/app_settings.json'), '/');
    }

    protected function legacyCandidateSettingsFilePaths(): array
    {
        return [
            $this->legacySettingsFilePath(),
            $this->projectRoot().DIRECTORY_SEPARATOR.'backend'.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app_settings.json',
        ];
    }
}
