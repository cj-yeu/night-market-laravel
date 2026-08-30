<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogImportProposalMarket extends Model
{
    use HasFactory;

    protected $table = 'catalog_import_proposal_markets';

    protected $fillable = [
        'catalog_import_proposal_id',
        'matched_night_market_id',
        'name',
        'address',
        'city',
        'state',
        'description',
        'evidence_text',
        'confidence',
    ];

    protected function casts(): array
    {
        return ['confidence' => 'decimal:2'];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(CatalogImportProposal::class, 'catalog_import_proposal_id');
    }

    public function matchedNightMarket(): BelongsTo
    {
        return $this->belongsTo(NightMarket::class, 'matched_night_market_id');
    }

    public function operatingDays(): HasMany
    {
        return $this->hasMany(CatalogImportProposalOperatingDay::class)->orderBy('day_of_week');
    }

    public function stalls(): HasMany
    {
        return $this->hasMany(CatalogImportProposalStall::class)->orderBy('display_order')->orderBy('id');
    }
}
