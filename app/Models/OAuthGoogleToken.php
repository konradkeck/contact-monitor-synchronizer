<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OAuthGoogleToken extends Model
{
    protected $table = 'oauth_google_tokens';

    protected $fillable = [
        'system',
        'subject_email',
        'scopes',
        'refresh_token',
        'payload_json',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
        ];
    }
}
