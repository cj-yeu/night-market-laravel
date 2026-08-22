<?php

namespace App\Http\Requests\Review;

use App\Models\User;

class StoreReviewRequest extends ReviewContentRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_CLIENT;
    }
}
