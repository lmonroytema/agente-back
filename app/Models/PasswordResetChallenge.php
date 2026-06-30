<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordResetChallenge extends Model
{
    protected $fillable = [
        'reset_id',
        'email',
        'token_hash',
        'user_name',
        'sent_at',
        'expires_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
