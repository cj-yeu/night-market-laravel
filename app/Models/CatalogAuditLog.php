<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogAuditLog extends Model
{
    use HasFactory;

    public const ENTITY_MARKET = 'night_market';

    public const ENTITY_STALL = 'stall';

    public const ENTITY_FOOD = 'food';

    public const ENTITY_USER = 'user';

    protected $fillable = ['user_id', 'entity_type', 'entity_id', 'action', 'summary', 'changed_fields'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['changed_fields' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
