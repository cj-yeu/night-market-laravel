<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogImportProposalStall extends Model
{
    use HasFactory;

    protected $table = 'catalog_import_proposal_stalls';

    protected $fillable = [
        'catalog_import_proposal_market_id',
        'matched_stall_id',
        'name',
        'description',
        'halal_status',
        'evidence_text',
        'confidence',
        'display_order',
    ];

    protected function casts(): array
    {
        return ['confidence' => 'decimal:2'];
    }

    public function proposalMarket(): BelongsTo
    {
        return $this->belongsTo(CatalogImportProposalMarket::class, 'catalog_import_proposal_market_id');
    }

    public function matchedStall(): BelongsTo
    {
        return $this->belongsTo(Stall::class, 'matched_stall_id');
    }

    public function foods(): HasMany
    {
        return $this->hasMany(CatalogImportProposalFood::class)->orderBy('display_order')->orderBy('id');
    }
}
