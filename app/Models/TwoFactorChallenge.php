<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TwoFactorChallenge extends Model
{
    protected $fillable = [
        'challenge_id',
        'email',
        'code',
        'is_admin',
        'user_name',
        'expires_at',
    ];

    protected $casts = [
        'is_admin' => 'boolean',
        'expires_at' => 'datetime',
    ];
}
