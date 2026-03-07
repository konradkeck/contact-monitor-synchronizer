<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceImapMessage extends Model
{
    protected $table = 'source_imap_messages';

    protected $fillable = [
        'account',
        'mailbox',
        'uid',
        'message_id',
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
