<?php

namespace App\Http\Requests\VisitPlan;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class PlannerSnapshotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_CLIENT;
    }

    public function rules(): array
    {
        return ['snapshot_id' => ['required', 'uuid']];
    }
}
