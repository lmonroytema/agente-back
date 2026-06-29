<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_setting_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint_id');
            $table->string('name');
            $table->string('base_url')->nullable();
            $table->string('auth_method')->nullable();
            $table->string('owner')->nullable();
            $table->string('pii_scope')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['app_setting_id', 'endpoint_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_endpoints');
    }
};
