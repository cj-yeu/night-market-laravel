<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\NightMarketController;
use App\Http\Controllers\Admin\SocialMediaRecordController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Client\ClientHomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');

    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->name('register.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/client/home', ClientHomeController::class)
        ->middleware('role:client')
        ->name('client.home');

    Route::get('/admin/dashboard', AdminDashboardController::class)
        ->middleware('role:admin')
        ->name('admin.dashboard');

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/night-markets/create', [NightMarketController::class, 'create'])
            ->name('night-markets.create');
        Route::post('/night-markets', [NightMarketController::class, 'store'])
            ->name('night-markets.store');

        Route::get('/social-media-records/create', [SocialMediaRecordController::class, 'create'])
            ->name('social-media-records.create');
        Route::post('/social-media-records', [SocialMediaRecordController::class, 'store'])
            ->name('social-media-records.store');
    });
});
