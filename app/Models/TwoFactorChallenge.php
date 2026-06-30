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
        'attempts',
        'max_attempts',
        'sent_at',
        'expires_at',
    ];

    protected $casts = [
        'is_admin' => 'boolean',
        'attempts' => 'integer',
        'max_attempts' => 'integer',
        'sent_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
