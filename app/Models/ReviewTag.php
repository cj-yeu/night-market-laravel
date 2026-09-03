<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ReviewTag extends Model
{
    use HasFactory;

    public const NAMES = [
        'Tasty',
        'Affordable',
        'Friendly Staff',
        'Clean',
        'Crowded',
        'Family-Friendly',
        'Worth Visiting',
        'Good Variety',
    ];

    protected $fillable = ['name'];

    public $timestamps = false;

    public function reviews(): BelongsToMany
    {
        return $this->belongsToMany(Review::class);
    }
}
