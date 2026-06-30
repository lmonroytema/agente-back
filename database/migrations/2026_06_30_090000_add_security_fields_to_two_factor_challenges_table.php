<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('two_factor_challenges', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempts')->default(0)->after('user_name');
            $table->unsignedSmallInteger('max_attempts')->default(5)->after('attempts');
            $table->timestamp('sent_at')->nullable()->after('max_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('two_factor_challenges', function (Blueprint $table) {
            $table->dropColumn(['attempts', 'max_attempts', 'sent_at']);
        });
    }
};
