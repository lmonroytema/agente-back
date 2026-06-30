<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Carbon;

class AdminUser extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'email',
        'password_hash',
        'password_changed_at',
        'role',
        'active',
        'two_factor_enabled',
    ];

    protected $casts = [
        'active' => 'boolean',
        'two_factor_enabled' => 'boolean',
        'password_changed_at' => 'datetime',
    ];

    public function markPasswordChanged(): void
    {
        $this->password_changed_at = Carbon::now();
    }
}
