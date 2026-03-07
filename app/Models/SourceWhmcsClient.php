<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceWhmcsClient extends Model
{
    protected $fillable = [
        'source_system',
        'source_record_id',
        'row_hash',
        'payload_json',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
        ];
    }
}
