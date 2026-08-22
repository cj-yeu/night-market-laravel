<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccount extends Model
{
    public const PROVIDER_GOOGLE = 'google';

    protected $fillable = [
        'provider',
        'provider_user_id',
        'provider_email',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
