<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Services\ClientDashboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClientHomeController extends Controller
{
    /**
     * Display the client dashboard.
     */
    public function __invoke(Request $request, ClientDashboardService $dashboardService): View
    {
        return view('client.home', $dashboardService->summaryFor($request->user()));
    }
}
