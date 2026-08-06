<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketOperatingDay extends Model
{
    use HasFactory;

    public const DAYS = [
        'Monday',
        'Tuesday',
        'Wednesday',
        'Thursday',
        'Friday',
        'Saturday',
        'Sunday',
    ];

    protected $fillable = [
        'day_of_week',
        'opening_time',
        'closing_time',
    ];

    protected function casts(): array
    {
        return [
            'opening_time' => 'datetime:H:i',
            'closing_time' => 'datetime:H:i',
        ];
    }

    public function nightMarket(): BelongsTo
    {
        return $this->belongsTo(NightMarket::class);
    }
}
