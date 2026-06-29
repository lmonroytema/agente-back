<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use App\Models\CorporateEndpoint;
use App\Services\AppSettingsService;
use Illuminate\Database\Seeder;

class CorporateEndpointSeeder extends Seeder
{
    public function run(): void
    {
        $setting = AppSetting::query()->firstWhere('singleton_key', 'default');

        if (! $setting) {
            $this->call(AppConfigurationSeeder::class);
            $setting = AppSetting::query()->firstWhere('singleton_key', 'default');
        }

        if (! $setting) {
            return;
        }

        $endpoints = [
            [
                'endpoint_id' => 'erp-operaciones',
                'name' => 'ERP de operaciones',
                'base_url' => '[PENDIENTE_EMPRESA] URL base del ERP',
                'auth_method' => '[PENDIENTE_EMPRESA] método de autenticación',
                'owner' => '[PENDIENTE_EMPRESA] responsable del ERP',
                'pii_scope' => 'Medio - revisar datos de contratos, clientes y órdenes',
                'enabled' => false,
            ],
            [
                'endpoint_id' => 'crm-clientes',
                'name' => 'CRM de clientes y cuentas',
                'base_url' => '[PENDIENTE_EMPRESA] URL base del CRM',
                'auth_method' => '[PENDIENTE_EMPRESA] OAuth2 o API Key',
                'owner' => '[PENDIENTE_EMPRESA] responsable comercial',
                'pii_scope' => 'Alto - puede contener PII de clientes y contactos',
                'enabled' => false,
            ],
            [
                'endpoint_id' => 'sharepoint-documentos',
                'name' => 'Repositorio documental SharePoint',
                'base_url' => '[PENDIENTE_EMPRESA] sitio o colección de SharePoint',
                'auth_method' => 'Microsoft Graph',
                'owner' => '[PENDIENTE_EMPRESA] responsable documental',
                'pii_scope' => 'Medio - validar bibliotecas con información sensible',
                'enabled' => false,
            ],
            [
                'endpoint_id' => 'rrhh-colaboradores',
                'name' => 'RRHH y colaboradores',
                'base_url' => '[PENDIENTE_EMPRESA] URL base del sistema RRHH',
                'auth_method' => '[PENDIENTE_EMPRESA] método de autenticación',
                'owner' => '[PENDIENTE_EMPRESA] responsable de RRHH',
                'pii_scope' => 'Alto - contiene datos personales de colaboradores',
                'enabled' => false,
            ],
            [
                'endpoint_id' => 'ambiental-monitoreo',
                'name' => 'Monitoreo ambiental y laboratorio',
                'base_url' => '[PENDIENTE_EMPRESA] URL base del sistema ambiental',
                'auth_method' => '[PENDIENTE_EMPRESA] método de autenticación',
                'owner' => '[PENDIENTE_EMPRESA] responsable ambiental',
                'pii_scope' => 'Bajo - priorizar datos operativos y trazabilidad',
                'enabled' => false,
            ],
            [
                'endpoint_id' => 'mesa-ayuda',
                'name' => 'Mesa de ayuda e incidentes',
                'base_url' => '[PENDIENTE_EMPRESA] URL base de la mesa de ayuda',
                'auth_method' => '[PENDIENTE_EMPRESA] método de autenticación',
                'owner' => '[PENDIENTE_EMPRESA] responsable de soporte',
                'pii_scope' => 'Medio - revisar tickets con datos personales o adjuntos',
                'enabled' => false,
            ],
        ];

        foreach ($endpoints as $endpoint) {
            CorporateEndpoint::query()->updateOrCreate(
                [
                    'app_setting_id' => $setting->id,
                    'endpoint_id' => $endpoint['endpoint_id'],
                ],
                $endpoint
            );
        }

        app(AppSettingsService::class)->syncLegacyJson($setting->fresh('corporateEndpoints'));
    }
}
