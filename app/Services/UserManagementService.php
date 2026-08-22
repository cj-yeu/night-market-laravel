<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserManagementService
{
    public function __construct(private readonly CatalogAuditLogService $catalogAuditLogService) {}

    /**
     * @param  array{search?: string|null, role?: string|null, status?: string|null, verification?: string|null, auth_method?: string|null}  $filters
     * @return LengthAwarePaginator<User>
     */
    public function users(array $filters): LengthAwarePaginator
    {
        return User::query()
            ->select([
                'id',
                'name',
                'email',
                'role',
                'is_active',
                'email_verified_at',
                'avatar_path',
                'created_at',
                'updated_at',
            ])
            ->selectRaw('password IS NOT NULL AS has_local_password')
            ->with(['googleAccount:id,user_id,provider'])
            ->search($filters['search'] ?? null)
            ->withRole($filters['role'] ?? null)
            ->withActiveStatus($filters['status'] ?? null)
            ->withVerificationStatus($filters['verification'] ?? null)
            ->withAuthenticationMethod($filters['auth_method'] ?? null)
            ->latest('created_at')
            ->paginate(15)
            ->withQueryString();
    }

    public function details(User $user): User
    {
        return User::query()
            ->select([
                'id',
                'name',
                'email',
                'role',
                'is_active',
                'email_verified_at',
                'avatar_path',
                'created_at',
                'updated_at',
            ])
            ->selectRaw('password IS NOT NULL AS has_local_password')
            ->with(['googleAccount:id,user_id,provider'])
            ->withCount(['reviews', 'visitPlans'])
            ->findOrFail($user->id);
    }

    public function updateStatus(User $admin, User $user, bool $isActive): User
    {
        if ($admin->is($user) && ! $isActive) {
            throw ValidationException::withMessages([
                'is_active' => 'You cannot deactivate your own account.',
            ]);
        }

        if ($user->role !== User::ROLE_CLIENT) {
            throw ValidationException::withMessages([
                'is_active' => 'Only Client account status can be managed here.',
            ]);
        }

        DB::transaction(function () use ($user, $isActive): void {
            $user->forceFill(['is_active' => $isActive])->save();

            if (! $isActive && config('session.driver') === 'database') {
                DB::connection(config('session.connection'))
                    ->table(config('session.table', 'sessions'))
                    ->where('user_id', $user->id)
                    ->delete();
            }
        });

        return $user->refresh();
    }

    public function promoteClient(User $actor, User $target): User
    {
        if (! $actor->isSuperAdmin()) {
            throw ValidationException::withMessages(['role' => 'Only a Super Admin can manage roles.']);
        }
        if ($target->role !== User::ROLE_CLIENT || ! $target->is_active || ! $target->hasVerifiedEmail()) {
            throw ValidationException::withMessages(['role' => 'Only active, email-verified Client accounts can be promoted.']);
        }

        return DB::transaction(function () use ($actor, $target): User {
            $target->forceFill(['role' => User::ROLE_ADMIN])->save();
            $updated = $target->refresh();
            $this->catalogAuditLogService->record($actor, $updated, 'role_promoted', 'Promoted Client to Admin', [
                'role' => ['label' => 'Role', 'before' => User::ROLE_CLIENT, 'after' => User::ROLE_ADMIN],
            ]);

            return $updated;
        });
    }

    public function demoteAdmin(User $actor, User $target): User
    {
        if (! $actor->isSuperAdmin()) {
            throw ValidationException::withMessages(['role' => 'Only a Super Admin can manage roles.']);
        }
        if ($actor->is($target) || $target->role === User::ROLE_SUPER_ADMIN || $target->role !== User::ROLE_ADMIN) {
            throw ValidationException::withMessages(['role' => 'Only another Admin account can be demoted to Client.']);
        }

        return DB::transaction(function () use ($actor, $target): User {
            $target->forceFill(['role' => User::ROLE_CLIENT])->save();
            $updated = $target->refresh();
            $this->catalogAuditLogService->record($actor, $updated, 'role_demoted', 'Demoted Admin to Client', [
                'role' => ['label' => 'Role', 'before' => User::ROLE_ADMIN, 'after' => User::ROLE_CLIENT],
            ]);

            return $updated;
        });
    }
}
