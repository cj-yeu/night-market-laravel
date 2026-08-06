<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\FoodController;
use App\Http\Controllers\Admin\NightMarketController;
use App\Http\Controllers\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Admin\StallController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Client\ClientHomeController;
use App\Http\Controllers\Client\NightMarketDiscoveryController;
use App\Http\Controllers\Client\ReviewController;
use App\Http\Controllers\Client\StallFoodDiscoveryController;
use App\Http\Controllers\Client\VisitPlanController;
use App\Http\Controllers\UserAccount\ProfileController;
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

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/profile/password', [ProfileController::class, 'editPassword'])->name('profile.password.edit');
    Route::patch('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');

    Route::get('/client/home', ClientHomeController::class)
        ->middleware('role:client')
        ->name('client.home');

    Route::middleware('role:client')->prefix('client')->name('client.')->group(function () {
        Route::get('/night-markets', [NightMarketDiscoveryController::class, 'index'])
            ->name('night-markets.index');

        Route::get('/night-markets/{nightMarket}', [NightMarketDiscoveryController::class, 'show'])
            ->whereNumber('nightMarket')
            ->name('night-markets.show');

        Route::get('/night-markets/{nightMarket}/stalls', [StallFoodDiscoveryController::class, 'index'])
            ->whereNumber('nightMarket')
            ->name('night-markets.stalls.index');

        Route::get('/foods/{food}', [StallFoodDiscoveryController::class, 'show'])
            ->whereNumber('food')
            ->name('foods.show');

        Route::get('/night-markets/{nightMarket}/reviews/create', [ReviewController::class, 'create'])
            ->whereNumber('nightMarket')
            ->name('night-markets.reviews.create');

        Route::post('/night-markets/{nightMarket}/reviews', [ReviewController::class, 'store'])
            ->whereNumber('nightMarket')
            ->name('night-markets.reviews.store');

        Route::get('/visit-plans', [VisitPlanController::class, 'index'])
            ->name('visit-plans.index');

        Route::get('/visit-plans/create', [VisitPlanController::class, 'create'])
            ->name('visit-plans.create');

        Route::post('/visit-plans', [VisitPlanController::class, 'store'])
            ->name('visit-plans.store');
    });

    Route::get('/admin/dashboard', AdminDashboardController::class)
        ->middleware('role:admin')
        ->name('admin.dashboard');

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/night-markets/create', [NightMarketController::class, 'create'])
            ->name('night-markets.create');

        Route::post('/night-markets', [NightMarketController::class, 'store'])
            ->name('night-markets.store');

        Route::get('/stalls/create', [StallController::class, 'create'])
            ->name('stalls.create');

        Route::post('/stalls', [StallController::class, 'store'])
            ->name('stalls.store');

        Route::get('/foods/create', [FoodController::class, 'create'])
            ->name('foods.create');

        Route::post('/foods', [FoodController::class, 'store'])
            ->name('foods.store');

        Route::get('/reviews', [AdminReviewController::class, 'index'])
            ->name('reviews.index');

        Route::patch('/reviews/{review}', [AdminReviewController::class, 'update'])
            ->whereNumber('review')
            ->name('reviews.update');
    });
});