<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Food extends Model
{
    use HasFactory;

    protected $table = 'foods';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'stall_id',
        'name',
        'description',
        'category',
        'is_must_try',
        'status',
    ];

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->whereHas('stall', fn (Builder $query) => $query->publiclyVisible());
    }

    protected function casts(): array
    {
        return [
            'is_must_try' => 'boolean',
        ];
    }

    public function stall(): BelongsTo
    {
        return $this->belongsTo(Stall::class);
    }

    public function socialMediaRecords(): HasMany
    {
        return $this->hasMany(SocialMediaRecord::class);
    }
}
