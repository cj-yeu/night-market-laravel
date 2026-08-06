<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    /**
     * Display the admin dashboard placeholder.
     */
    public function __invoke(): View
    {
        return view('admin.dashboard');
    }
}
