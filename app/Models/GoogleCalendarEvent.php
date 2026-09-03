<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleCalendarEvent extends Model
{
    public const SYNC_STATUS_SYNCED = 'synced';

    public const SYNC_STATUS_FAILED = 'failed';

    public const SYNC_STATUS_RECONNECT_REQUIRED = 'reconnect_required';

    protected $fillable = [
        'visit_plan_id',
        'google_event_id',
        'google_event_url',
        'payload_hash',
        'sync_status',
        'last_sync_error_code',
        'last_synced_at',
        'last_sync_failed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
            'last_sync_failed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function visitPlan(): BelongsTo
    {
        return $this->belongsTo(VisitPlan::class);
    }
}
