<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class UserManagementService
{
    /**
     * @param  array{search?: string|null, role?: string|null, status?: string|null}  $filters
     * @return LengthAwarePaginator<User>
     */
    public function users(array $filters): LengthAwarePaginator
    {
        return User::query()
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->when($filters['role'] ?? null, fn ($query, string $role) => $query->where('role', $role))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query
                ->where('is_active', $status === 'active'))
            ->latest('created_at')
            ->paginate(15)
            ->withQueryString();
    }

    public function updateStatus(User $admin, User $user, bool $isActive): User
    {
        if ($admin->is($user) && ! $isActive) {
            throw ValidationException::withMessages([
                'is_active' => 'You cannot deactivate your own account.',
            ]);
        }

        $user->update(['is_active' => $isActive]);

        return $user->refresh();
    }
}
