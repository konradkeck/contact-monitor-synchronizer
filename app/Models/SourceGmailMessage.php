<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceGmailMessage extends Model
{
    protected $table = 'source_gmail_messages';

    protected $fillable = [
        'system',
        'subject_email',
        'external_id',
        'thread_id',
        'internal_date',
        'row_hash',
        'payload_json',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'fetched_at'   => 'datetime',
        ];
    }
}
