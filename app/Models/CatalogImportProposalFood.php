<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogImportProposalFood extends Model
{
    use HasFactory;

    protected $table = 'catalog_import_proposal_foods';

    protected $fillable = [
        'catalog_import_proposal_stall_id',
        'matched_food_id',
        'name',
        'category',
        'description',
        'price_display',
        'price_min',
        'price_max',
        'is_must_try',
        'evidence_text',
        'confidence',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'price_min' => 'decimal:2',
            'price_max' => 'decimal:2',
            'is_must_try' => 'boolean',
            'confidence' => 'decimal:2',
        ];
    }

    public function proposalStall(): BelongsTo
    {
        return $this->belongsTo(CatalogImportProposalStall::class, 'catalog_import_proposal_stall_id');
    }

    public function matchedFood(): BelongsTo
    {
        return $this->belongsTo(Food::class, 'matched_food_id');
    }
}
