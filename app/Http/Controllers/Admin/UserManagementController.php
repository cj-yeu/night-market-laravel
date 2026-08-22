<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DemoteUserToClientRequest;
use App\Http\Requests\Admin\PromoteUserToAdminRequest;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Http\Requests\Admin\UserManagementFilterRequest;
use App\Models\User;
use App\Services\UserManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\View;

class UserManagementController extends Controller
{
    public function __construct(private readonly UserManagementService $userManagementService) {}

    public function index(UserManagementFilterRequest $request): View
    {
        $filters = $request->validated();

        return view('admin.users.index', [
            'users' => $this->userManagementService->users($filters),
            'filters' => $filters,
            'canManageRoles' => $request->user()->isSuperAdmin(),
        ]);
    }

    public function show(Request $request, User $user): View
    {
        return view('admin.users.show', [
            'user' => $this->userManagementService->details($user),
            'canManageRoles' => $request->user()->isSuperAdmin(),
        ]);
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): RedirectResponse
    {
        $validated = $request->validated();
        $updatedUser = $this->userManagementService->updateStatus(
            $request->user(),
            $user,
            (bool) $validated['is_active'],
        );

        $message = $updatedUser->is_active
            ? 'The Client account was activated successfully.'
            : 'The Client account was deactivated successfully.';

        if (($validated['redirect_to'] ?? null) === 'show') {
            return redirect()
                ->route('admin.users.show', $updatedUser)
                ->with('status', $message);
        }

        return redirect()
            ->route('admin.users.index', Arr::only($validated, [
                'search',
                'role',
                'status',
                'verification',
                'auth_method',
                'page',
            ]))
            ->with('status', $message);
    }

    public function promote(PromoteUserToAdminRequest $request, User $user): RedirectResponse
    {
        $updatedUser = $this->userManagementService->promoteClient($request->user(), $user);

        return redirect()->route('admin.users.show', $updatedUser)->with('status', 'Promoted Client to Admin successfully.');
    }

    public function demote(DemoteUserToClientRequest $request, User $user): RedirectResponse
    {
        $updatedUser = $this->userManagementService->demoteAdmin($request->user(), $user);

        return redirect()->route('admin.users.show', $updatedUser)->with('status', 'Demoted Admin to Client successfully.');
    }
}
