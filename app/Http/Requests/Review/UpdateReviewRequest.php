<?php

namespace App\Http\Requests\Review;

use App\Models\Review;
use App\Models\User;

class UpdateReviewRequest extends ReviewContentRequest
{
    public function authorize(): bool
    {
        $review = $this->route('review');

        return $this->user()?->role === User::ROLE_CLIENT
            && $review instanceof Review
            && $review->user_id === $this->user()->id;
    }
}
