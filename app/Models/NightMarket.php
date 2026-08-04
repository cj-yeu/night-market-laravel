<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NightMarket extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'name',
        'address',
        'city',
        'state',
        'description',
        'status',
    ];

    public function operatingDays(): HasMany
    {
        return $this->hasMany(MarketOperatingDay::class);
    }

    public function stalls(): HasMany
    {
        return $this->hasMany(Stall::class);
    }
}
