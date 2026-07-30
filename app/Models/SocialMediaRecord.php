<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SocialMediaRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform',
        'post_url',
        'content',
        'post_date',
        'engagement_count',
        'mentioned_market_name',
        'mentioned_food_name',
    ];

    protected function casts(): array
    {
        return [
            'post_date' => 'date',
            'engagement_count' => 'integer',
        ];
    }
}
