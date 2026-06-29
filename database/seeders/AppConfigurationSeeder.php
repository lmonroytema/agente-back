<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use App\Services\AppSettingsService;
use Illuminate\Database\Seeder;

class AppConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(AppSettingsService::class);

        $template = [
            'singleton_key' => 'default',
            'app_name' => 'Tema Litoclean Control Center',
            'company_name' => 'Tema Litoclean',
            'company_industry' => 'Medioambiental',
            'preferred_surface' => 'Web',
            'deployment_target' => 'Cloud',
            'corporate_identity_provider' => 'Validación por dominio corporativo (preparado para Entra ID)',
            'require_corporate_email' => true,
            'allowed_domains' => ['tema.com.pe', 'tema.es'],
            'require_two_factor' => true,
            'corporate_api_base_url' => '[PENDIENTE_EMPRESA] URL base del gateway o API corporativa',
            'corporate_api_auth_method' => '[PENDIENTE_EMPRESA] OAuth2, API Key, Basic o mTLS',
            'corporate_api_key_endpoints' => implode("\n", [
                'Información a solicitar a la empresa para las APIs corporativas:',
                '- Catálogo de endpoints disponibles por dominio funcional.',
                '- URL base por ambiente: desarrollo, QA y producción.',
                '- Método de autenticación y scopes requeridos.',
                '- Responsable técnico y funcional por integración.',
                '- Límites de consumo, rate limits y ventanas de mantenimiento.',
                '- Contrato de datos, ejemplos de payload y códigos de error.',
            ]),
            'data_restrictions' => implode("\n", [
                'Información a solicitar a la empresa sobre restricciones de datos:',
                '- Qué datos no pueden salir del entorno corporativo.',
                '- País o región autorizada para procesamiento y almacenamiento.',
                '- Datos que exigen aprobación humana antes de enviarse.',
                '- Reglas para compartir información con terceros o proveedores.',
            ]),
            'pii_policy' => implode("\n", [
                'Información a solicitar a la empresa sobre PII:',
                '- Qué campos se consideran datos personales o sensibles.',
                '- Reglas de anonimización, enmascaramiento y minimización.',
                '- Casos permitidos y prohibidos para usar PII en IA o reportes.',
                '- Responsable de cumplimiento legal y de seguridad.',
            ]),
            'retention_policy' => implode("\n", [
                'Información a solicitar a la empresa sobre retención:',
                '- Tiempo de conservación para uploads, salidas y logs.',
                '- Política de borrado, archivado y recuperación.',
                '- Requisitos de auditoría, evidencias y trazabilidad.',
            ]),
            'approved_gen_ai_provider' => '[PENDIENTE_EMPRESA] Proveedor aprobado de IA generativa',
            'anthropic_api_key' => '[PENDIENTE_EMPRESA] Registrar credencial en vault corporativo',
            'enable_anthropic_routing' => false,
            'gen_image_api_provider' => '[PENDIENTE_EMPRESA] Proveedor de imágenes',
            'gen_video_api_provider' => '[PENDIENTE_EMPRESA] Proveedor de video',
            'gen_api_budget_monthly' => '[PENDIENTE_EMPRESA] Presupuesto mensual máximo',
            'gen_api_budget_alert_threshold' => '80%',
            'office_graph_tenant_id' => '[PENDIENTE_EMPRESA] Tenant ID de Microsoft 365',
            'office_graph_client_id' => '[PENDIENTE_EMPRESA] Client ID de la aplicación',
            'office_graph_client_secret' => '[PENDIENTE_EMPRESA] Secret en gestor corporativo',
            'admin_user_emails' => ['admin@tema.com.pe', 'seguridad@tema.es'],
            'allowed_origins' => ['http://localhost:5173'],
            'storage_dir' => 'storage/app/tema_litoclean',
            'audit_dir' => 'storage/app/tema_litoclean/audit',
            'mock_corporate_data' => true,
            'phase1_priority_use_cases' => implode("\n", [
                'Casos de uso a confirmar con la empresa:',
                '- Consulta de servicios medioambientales.',
                '- Automatización PDF y limpieza de folios.',
                '- Generación de reportes, presentaciones y formularios.',
                '- Integraciones con Microsoft 365 y APIs corporativas.',
            ]),
            'observability_notes' => implode("\n", [
                'Información a solicitar a la empresa para operación y soporte:',
                '- Equipo responsable de soporte funcional y técnico.',
                '- Canal de incidentes y criticidad esperada.',
                '- Métricas clave: uso, errores, tiempos de respuesta y costo.',
                '- Herramientas de monitoreo, logging y alertamiento.',
            ]),
        ];

        $setting = AppSetting::query()->firstWhere('singleton_key', 'default');

        if (! $setting) {
            $setting = AppSetting::query()->create($template);
        } else {
            $attributes = $setting->getAttributes();

            foreach ($template as $key => $value) {
                $current = $attributes[$key] ?? $setting->{$key} ?? null;

                if (is_array($value)) {
                    if (! is_array($current) || empty($current)) {
                        $setting->{$key} = $value;
                    }

                    continue;
                }

                if ($current === null || trim((string) $current) === '') {
                    $setting->{$key} = $value;
                }
            }

            $setting->save();
        }

        $service->syncLegacyJson($setting->fresh('corporateEndpoints'));
    }
}
