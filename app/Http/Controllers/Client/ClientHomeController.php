<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class ClientHomeController extends Controller
{
    /**
     * Display the client home placeholder.
     */
    public function __invoke(): View
    {
        return view('client.home');
    }
}
