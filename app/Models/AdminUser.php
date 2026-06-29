<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class AdminUser extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'email',
        'role',
        'active',
        'two_factor_enabled',
    ];

    protected $casts = [
        'active' => 'boolean',
        'two_factor_enabled' => 'boolean',
    ];
}
