<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('singleton_key')->unique();
            $table->string('app_name');
            $table->string('company_name');
            $table->string('company_industry');
            $table->string('preferred_surface');
            $table->string('deployment_target');
            $table->string('corporate_identity_provider');
            $table->boolean('require_corporate_email')->default(true);
            $table->json('allowed_domains');
            $table->boolean('require_two_factor')->default(true);
            $table->string('corporate_api_base_url')->nullable();
            $table->string('corporate_api_auth_method')->nullable();
            $table->text('corporate_api_key_endpoints')->nullable();
            $table->text('data_restrictions')->nullable();
            $table->text('pii_policy')->nullable();
            $table->text('retention_policy')->nullable();
            $table->string('approved_gen_ai_provider')->nullable();
            $table->text('anthropic_api_key')->nullable();
            $table->boolean('enable_anthropic_routing')->default(false);
            $table->string('gen_image_api_provider')->nullable();
            $table->string('gen_video_api_provider')->nullable();
            $table->string('gen_api_budget_monthly')->nullable();
            $table->string('gen_api_budget_alert_threshold')->nullable();
            $table->string('office_graph_tenant_id')->nullable();
            $table->string('office_graph_client_id')->nullable();
            $table->text('office_graph_client_secret')->nullable();
            $table->json('admin_user_emails');
            $table->json('allowed_origins');
            $table->string('storage_dir');
            $table->string('audit_dir');
            $table->boolean('mock_corporate_data')->default(true);
            $table->text('phase1_priority_use_cases');
            $table->text('observability_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
