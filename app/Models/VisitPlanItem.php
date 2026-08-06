<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitPlanItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'item_type',
        'item_name',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function visitPlan(): BelongsTo
    {
        return $this->belongsTo(VisitPlan::class);
    }
}
