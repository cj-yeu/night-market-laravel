<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Http\Requests\Admin\UserManagementFilterRequest;
use App\Models\User;
use App\Services\UserManagementService;
use Illuminate\Http\RedirectResponse;
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
        ]);
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): RedirectResponse
    {
        $updatedUser = $this->userManagementService->updateStatus(
            $request->user(),
            $user,
            $request->boolean('is_active'),
        );

        return redirect()
            ->route('admin.users.index', $request->only(['search', 'role', 'status', 'page']))
            ->with('status', $updatedUser->is_active
                ? 'The user account was activated successfully.'
                : 'The user account was deactivated successfully.');
    }
}
