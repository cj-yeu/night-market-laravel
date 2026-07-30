<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VisitPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'night_market_id',
        'title',
        'visit_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'visit_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function nightMarket(): BelongsTo
    {
        return $this->belongsTo(NightMarket::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(VisitPlanItem::class)->orderBy('sort_order');
    }
}
