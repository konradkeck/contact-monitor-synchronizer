<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceMetricscubeClientActivity extends Model
{
    protected $table = 'source_metricscube_client_activities';

    protected $fillable = [
        'system',
        'external_id',
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
