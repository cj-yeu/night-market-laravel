<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogImportProposalOperatingDay extends Model
{
    use HasFactory;

    protected $table = 'catalog_import_proposal_operating_days';

    protected $fillable = [
        'catalog_import_proposal_market_id',
        'day_of_week',
        'opening_time',
        'closing_time',
        'evidence_text',
        'confidence',
    ];

    protected function casts(): array
    {
        return [
            'opening_time' => 'datetime:H:i',
            'closing_time' => 'datetime:H:i',
            'confidence' => 'decimal:2',
        ];
    }

    public function proposalMarket(): BelongsTo
    {
        return $this->belongsTo(CatalogImportProposalMarket::class, 'catalog_import_proposal_market_id');
    }
}
