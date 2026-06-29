<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AppSettingsService;
use Illuminate\Http\JsonResponse;

class ConfigController extends Controller
{
    public function __invoke(AppSettingsService $appSettings): JsonResponse
    {
        $settings = $appSettings->toPayload($appSettings->ensureDefaults());
        $items = [
            $this->item('app_name', 'Nombre de la aplicación', $settings['app_name'], $this->isConfiguredValue($settings['app_name']), 'Nombre visible y técnico de la aplicación corporativa.'),
            $this->item('company', 'Empresa y rubro', $settings['company_name'].' | '.$settings['company_industry'], $this->isConfiguredValue($settings['company_name']) && $this->isConfiguredValue($settings['company_industry']), 'Define la empresa y su rubro principal.'),
            $this->item('corporate_api', 'APIs corporativas', $settings['corporate_api_base_url'], $this->isConfiguredValue($settings['corporate_api_base_url']) || ! empty($settings['corporate_endpoints']), 'Base URL, autenticación y catálogo de endpoints corporativos.'),
            $this->item('surface', 'Superficie de uso', $settings['preferred_surface'], $this->isConfiguredValue($settings['preferred_surface']), 'Canal principal para operar la aplicación.'),
            $this->item('deployment', 'Despliegue', $settings['deployment_target'], $this->isConfiguredValue($settings['deployment_target']), 'Destino de despliegue principal.'),
            $this->item('restrictions', 'Restricciones de datos', $settings['data_restrictions'], $this->isConfiguredValue($settings['data_restrictions']), 'Define restricciones para compartir datos fuera del entorno corporativo.'),
            $this->item('pii_policy', 'Política PII', $settings['pii_policy'], $this->isConfiguredValue($settings['pii_policy']), 'Reglas para tratar datos personales y sensibles.'),
            $this->item('retention', 'Retención y auditoría', $settings['retention_policy'], $this->isConfiguredValue($settings['retention_policy']), 'Criterios de retención, trazabilidad y evidencia operativa.'),
            $this->item('gen_apis', 'APIs generativas', $this->joinValues([$settings['approved_gen_ai_provider'], $settings['gen_image_api_provider'], $settings['gen_video_api_provider']]), $this->isConfiguredValue($settings['approved_gen_ai_provider']) || $this->isConfiguredValue($settings['gen_image_api_provider']) || $this->isConfiguredValue($settings['gen_video_api_provider']), 'Proveedores aprobados para capacidades generativas.'),
            $this->item('budget', 'Presupuesto IA', $this->joinValues([$settings['gen_api_budget_monthly'], $settings['gen_api_budget_alert_threshold']]), $this->isConfiguredValue($settings['gen_api_budget_monthly']) || $this->isConfiguredValue($settings['gen_api_budget_alert_threshold']), 'Límite mensual y umbral de alerta del consumo generativo.'),
            $this->item('phase1', 'Casos de uso prioritarios', $settings['phase1_priority_use_cases'], $this->isConfiguredValue($settings['phase1_priority_use_cases']), 'Casos priorizados para escalar la aplicación.'),
            $this->item('observability', 'Observabilidad', $settings['observability_notes'], $this->isConfiguredValue($settings['observability_notes']), 'Notas de monitoreo, soporte y seguimiento operativo.'),
            $this->item('graph', 'Microsoft Graph', $this->joinValues([$settings['office_graph_tenant_id'], $settings['office_graph_client_id']]), $this->isConfiguredValue($settings['office_graph_tenant_id']) || $this->isConfiguredValue($settings['office_graph_client_id']), 'Credenciales base para integraciones Microsoft 365.'),
            $this->item('anthropic', 'Routing Anthropic', $settings['enable_anthropic_routing'] ? 'habilitado' : $settings['anthropic_api_key'], $this->isConfiguredValue($settings['anthropic_api_key']) || $settings['enable_anthropic_routing'], 'Proveedor y switch para ruteo avanzado por modelo.'),
            $this->item('access', 'Acceso corporativo', implode(', ', $settings['allowed_domains']), ! empty($settings['allowed_domains']), 'Usuarios corporativos con dominios validados y doble autenticación.'),
            $this->item('origins', 'Orígenes permitidos', implode(', ', $settings['allowed_origins']), ! empty($settings['allowed_origins']), 'Orígenes CORS permitidos para frontend u otros consumidores.'),
            $this->item('storage', 'Rutas operativas', $this->joinValues([$settings['storage_dir'], $settings['audit_dir']]), $this->isConfiguredValue($settings['storage_dir']) && $this->isConfiguredValue($settings['audit_dir']), 'Directorios de almacenamiento y auditoría del App.'),
        ];

        return response()->json([
            'company_name' => $settings['company_name'],
            'theme' => 'Tema Litoclean',
            'preferred_surface' => $settings['preferred_surface'],
            'deployment_target' => $settings['deployment_target'],
            'items' => $items,
        ]);
    }

    protected function item(string $key, string $label, ?string $value, bool $configured, string $helpText): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'required' => true,
            'configured' => $configured,
            'help_text' => $helpText,
        ];
    }

    protected function joinValues(array $values): ?string
    {
        $filtered = array_values(array_filter($values, fn ($value) => filled($value)));

        return empty($filtered) ? null : implode(' | ', $filtered);
    }

    protected function isConfiguredValue(mixed $value): bool
    {
        if (is_array($value)) {
            return ! empty($value);
        }

        if (! filled($value)) {
            return false;
        }

        return ! str_contains(mb_strtoupper((string) $value), '[PENDIENTE_EMPRESA]');
    }
}
