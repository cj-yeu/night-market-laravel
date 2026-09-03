<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogCategory extends Model
{
    use HasFactory;

    public const TYPE_STALL = 'stall';

    public const TYPE_FOOD = 'food';

    public const TYPES = [self::TYPE_STALL, self::TYPE_FOOD];

    protected $fillable = [
        'category_type',
        'name',
        'normalized_name',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeForType(Builder $query, string $type): Builder
    {
        return $query->where('category_type', $type);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
