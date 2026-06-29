<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppSetting extends Model
{
    protected $fillable = [
        'singleton_key',
        'app_name',
        'company_name',
        'company_industry',
        'preferred_surface',
        'deployment_target',
        'corporate_identity_provider',
        'require_corporate_email',
        'allowed_domains',
        'require_two_factor',
        'corporate_api_base_url',
        'corporate_api_auth_method',
        'corporate_api_key_endpoints',
        'data_restrictions',
        'pii_policy',
        'retention_policy',
        'approved_gen_ai_provider',
        'anthropic_api_key',
        'enable_anthropic_routing',
        'gen_image_api_provider',
        'gen_video_api_provider',
        'gen_api_budget_monthly',
        'gen_api_budget_alert_threshold',
        'office_graph_tenant_id',
        'office_graph_client_id',
        'office_graph_client_secret',
        'admin_user_emails',
        'allowed_origins',
        'storage_dir',
        'audit_dir',
        'mock_corporate_data',
        'phase1_priority_use_cases',
        'observability_notes',
    ];

    protected $casts = [
        'require_corporate_email' => 'boolean',
        'allowed_domains' => 'array',
        'require_two_factor' => 'boolean',
        'enable_anthropic_routing' => 'boolean',
        'admin_user_emails' => 'array',
        'allowed_origins' => 'array',
        'mock_corporate_data' => 'boolean',
    ];

    public function corporateEndpoints(): HasMany
    {
        return $this->hasMany(CorporateEndpoint::class);
    }
}
