<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorporateEndpoint extends Model
{
    protected $fillable = [
        'app_setting_id',
        'endpoint_id',
        'name',
        'base_url',
        'auth_method',
        'owner',
        'pii_scope',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function appSetting(): BelongsTo
    {
        return $this->belongsTo(AppSetting::class);
    }
}
