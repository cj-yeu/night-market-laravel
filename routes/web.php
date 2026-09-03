<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\CatalogActivityController;
use App\Http\Controllers\Admin\CatalogDataQualityController;
use App\Http\Controllers\Admin\FoodController;
use App\Http\Controllers\Admin\NightMarketController;
use App\Http\Controllers\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Admin\SocialMediaAutomationController;
use App\Http\Controllers\Admin\SocialMediaExtractionController;
use App\Http\Controllers\Admin\SocialMediaRecordController;
use App\Http\Controllers\Admin\StallController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\GoogleAuthenticationController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Client\ClientHomeController;
use App\Http\Controllers\Client\GoogleCalendarController;
use App\Http\Controllers\Client\NightMarketDiscoveryController;
use App\Http\Controllers\Client\ReviewController;
use App\Http\Controllers\Client\SmartVisitPlannerController;
use App\Http\Controllers\Client\SocialMediaHighlightController;
use App\Http\Controllers\Client\StallFoodDiscoveryController;
use App\Http\Controllers\Client\VisitPlanController;
use App\Http\Controllers\UserAccount\GoogleAccountController;
use App\Http\Controllers\UserAccount\ProfileController;
use App\Http\Controllers\UserAccount\ProfileImageController;
use Illuminate\Support\Facades\Route;

Route::get('/', [NightMarketDiscoveryController::class, 'home'])->name('home');

Route::get('/night-markets', [NightMarketDiscoveryController::class, 'index'])
    ->name('night-markets.index');
Route::get('/night-markets/{nightMarket}', [NightMarketDiscoveryController::class, 'show'])
    ->whereNumber('nightMarket')
    ->name('night-markets.show');
Route::get('/night-markets/{nightMarket}/stalls', [StallFoodDiscoveryController::class, 'index'])
    ->whereNumber('nightMarket')
    ->name('night-markets.stalls.index');
Route::get('/stalls', [StallFoodDiscoveryController::class, 'stalls'])
    ->name('stalls.index');
Route::get('/foods', [StallFoodDiscoveryController::class, 'foods'])
    ->name('foods.index');
Route::get('/foods/{food}', [StallFoodDiscoveryController::class, 'show'])
    ->whereNumber('food')
    ->name('foods.show');
Route::get('/social-media-highlights', [SocialMediaHighlightController::class, 'index'])
    ->name('social-media-highlights.index');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');

    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->name('register.store');

    Route::get('/forgot-password', [ForgotPasswordController::class, 'create'])
        ->name('password.request');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'store'])
        ->name('password.email');
    Route::get('/reset-password/{token}', [ResetPasswordController::class, 'create'])
        ->name('password.reset');
    Route::post('/reset-password', [ResetPasswordController::class, 'store'])
        ->name('password.update');

    Route::get('/auth/google', [GoogleAuthenticationController::class, 'redirect'])
        ->name('auth.google.redirect');
});

Route::get('/auth/google/callback', [GoogleAuthenticationController::class, 'callback'])
    ->name('auth.google.callback');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/verify-email', [EmailVerificationController::class, 'notice'])
        ->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'store'])
        ->middleware('throttle:verification-email')
        ->name('verification.send');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/avatar', [ProfileImageController::class, 'update'])->name('profile.avatar.update');
    Route::delete('/profile/avatar', [ProfileImageController::class, 'destroy'])->name('profile.avatar.destroy');
    Route::post('/profile/connected-accounts/google', [GoogleAccountController::class, 'store'])
        ->middleware('role:client')
        ->name('profile.google.connect');
    Route::delete('/profile/connected-accounts/google', [GoogleAccountController::class, 'destroy'])
        ->middleware('role:client')
        ->name('profile.google.disconnect');
    Route::get('/profile/password', [ProfileController::class, 'editPassword'])->name('profile.password.edit');
    Route::patch('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');

    Route::get('/client/home', ClientHomeController::class)
        ->middleware(['role:client', 'verified'])
        ->name('client.home');

    Route::middleware(['role:client', 'verified'])->prefix('client')->name('client.')->group(function () {
        Route::get('/night-markets/{nightMarket}/reviews/create', [ReviewController::class, 'createMarket'])
            ->whereNumber('nightMarket')->name('night-markets.reviews.create');
        Route::post('/night-markets/{nightMarket}/reviews', [ReviewController::class, 'storeMarket'])
            ->whereNumber('nightMarket')->name('night-markets.reviews.store');
        Route::get('/night-markets/{nightMarket}/reviews/{review}/edit', [ReviewController::class, 'editMarket'])
            ->whereNumber('nightMarket')->whereNumber('review')->name('night-markets.reviews.edit');
        Route::patch('/night-markets/{nightMarket}/reviews/{review}', [ReviewController::class, 'updateMarket'])
            ->whereNumber('nightMarket')->whereNumber('review')->name('night-markets.reviews.update');
        Route::delete('/night-markets/{nightMarket}/reviews/{review}', [ReviewController::class, 'destroyMarket'])
            ->whereNumber('nightMarket')->whereNumber('review')->name('night-markets.reviews.destroy');

        Route::get('/foods/{food}/reviews/create', [ReviewController::class, 'create'])
            ->whereNumber('food')->name('foods.reviews.create');
        Route::post('/foods/{food}/reviews', [ReviewController::class, 'store'])
            ->whereNumber('food')->name('foods.reviews.store');
        Route::get('/foods/{food}/reviews/{review}/edit', [ReviewController::class, 'edit'])
            ->whereNumber('food')->whereNumber('review')->name('foods.reviews.edit');
        Route::patch('/foods/{food}/reviews/{review}', [ReviewController::class, 'update'])
            ->whereNumber('food')->whereNumber('review')->name('foods.reviews.update');
        Route::delete('/foods/{food}/reviews/{review}', [ReviewController::class, 'destroy'])
            ->whereNumber('food')->whereNumber('review')->name('foods.reviews.destroy');

        Route::get('/visit-plans', [VisitPlanController::class, 'index'])->name('visit-plans.index');
        Route::get('/visit-plans/smart-planner', [SmartVisitPlannerController::class, 'index'])
            ->name('visit-plans.smart-planner.index');
        Route::post('/visit-plans/smart-planner', [SmartVisitPlannerController::class, 'recommend'])
            ->name('visit-plans.smart-planner.recommend');
        Route::post('/visit-plans/smart-planner/create-plan', [SmartVisitPlannerController::class, 'store'])
            ->name('visit-plans.smart-planner.store');
        Route::get('/visit-plans/create', [VisitPlanController::class, 'create'])->name('visit-plans.create');
        Route::post('/visit-plans', [VisitPlanController::class, 'store'])->name('visit-plans.store');
        Route::get('/visit-plans/{visitPlan}', [VisitPlanController::class, 'show'])
            ->whereNumber('visitPlan')->name('visit-plans.show');
        Route::get('/visit-plans/{visitPlan}/edit', [VisitPlanController::class, 'edit'])
            ->whereNumber('visitPlan')->name('visit-plans.edit');
        Route::patch('/visit-plans/{visitPlan}', [VisitPlanController::class, 'update'])
            ->whereNumber('visitPlan')->name('visit-plans.update');
        Route::delete('/visit-plans/{visitPlan}', [VisitPlanController::class, 'destroy'])
            ->whereNumber('visitPlan')->name('visit-plans.destroy');

        Route::post('/visit-plans/{visitPlan}/items', [VisitPlanController::class, 'storeItem'])
            ->whereNumber('visitPlan')->name('visit-plans.items.store');
        Route::delete('/visit-plans/{visitPlan}/items/{visitPlanItem}', [VisitPlanController::class, 'destroyItem'])
            ->whereNumber('visitPlan')
            ->whereNumber('visitPlanItem')
            ->name('visit-plans.items.destroy');

    });

    Route::middleware(['role:client', 'verified'])->group(function () {
        Route::get('/client/visit-plans/{visitPlan}/google-calendar/connect', [GoogleCalendarController::class, 'connect'])
            ->whereNumber('visitPlan')->name('client.visit-plans.google-calendar.connect');
        Route::post('/client/visit-plans/{visitPlan}/google-calendar/sync', [GoogleCalendarController::class, 'sync'])
            ->whereNumber('visitPlan')->name('client.visit-plans.google-calendar.sync');
        Route::delete('/client/visit-plans/{visitPlan}/google-calendar', [GoogleCalendarController::class, 'destroy'])
            ->whereNumber('visitPlan')->name('client.visit-plans.google-calendar.destroy');
        Route::get('/integrations/google-calendar/callback', [GoogleCalendarController::class, 'callback'])
            ->name('client.google-calendar.callback');
    });

    Route::get('/admin/dashboard', AdminDashboardController::class)
        ->middleware('role:admin')
        ->name('admin.dashboard');

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/catalog-activity', [CatalogActivityController::class, 'index'])->name('catalog-activity.index');
        Route::get('/catalog-data-quality', [CatalogDataQualityController::class, 'index'])
            ->name('catalog-data-quality.index');
        Route::get('/catalog-data-quality/{issue}', [CatalogDataQualityController::class, 'records'])
            ->name('catalog-data-quality.records');

        Route::get('/night-markets', [NightMarketController::class, 'index'])->name('night-markets.index');
        Route::get('/night-markets/create', [NightMarketController::class, 'create'])->name('night-markets.create');
        Route::post('/night-markets', [NightMarketController::class, 'store'])->name('night-markets.store');
        Route::get('/night-markets/{nightMarket}', [NightMarketController::class, 'show'])
            ->whereNumber('nightMarket')->name('night-markets.show');
        Route::get('/night-markets/{nightMarket}/edit', [NightMarketController::class, 'edit'])
            ->whereNumber('nightMarket')->name('night-markets.edit');
        Route::patch('/night-markets/{nightMarket}', [NightMarketController::class, 'update'])
            ->whereNumber('nightMarket')->name('night-markets.update');
        Route::patch('/night-markets/{nightMarket}/activate', [NightMarketController::class, 'activate'])
            ->whereNumber('nightMarket')->name('night-markets.activate');
        Route::patch('/night-markets/{nightMarket}/deactivate', [NightMarketController::class, 'deactivate'])
            ->whereNumber('nightMarket')->name('night-markets.deactivate');
        Route::patch('/night-markets/{nightMarket}/image', [NightMarketController::class, 'updateImage'])
            ->whereNumber('nightMarket')->name('night-markets.image.update');
        Route::delete('/night-markets/{nightMarket}/image', [NightMarketController::class, 'deleteImage'])
            ->whereNumber('nightMarket')->name('night-markets.image.destroy');
        Route::delete('/night-markets/{nightMarket}', [NightMarketController::class, 'destroy'])
            ->whereNumber('nightMarket')->name('night-markets.destroy');

        Route::get('/stalls', [StallController::class, 'index'])->name('stalls.index');
        Route::get('/stalls/create', [StallController::class, 'create'])->name('stalls.create');
        Route::post('/stalls', [StallController::class, 'store'])->name('stalls.store');
        Route::get('/stalls/{stall}', [StallController::class, 'show'])
            ->whereNumber('stall')->name('stalls.show');
        Route::get('/stalls/{stall}/edit', [StallController::class, 'edit'])
            ->whereNumber('stall')->name('stalls.edit');
        Route::patch('/stalls/{stall}', [StallController::class, 'update'])
            ->whereNumber('stall')->name('stalls.update');
        Route::patch('/stalls/{stall}/activate', [StallController::class, 'activate'])
            ->whereNumber('stall')->name('stalls.activate');
        Route::patch('/stalls/{stall}/deactivate', [StallController::class, 'deactivate'])
            ->whereNumber('stall')->name('stalls.deactivate');
        Route::patch('/stalls/{stall}/image', [StallController::class, 'updateImage'])
            ->whereNumber('stall')->name('stalls.image.update');
        Route::delete('/stalls/{stall}/image', [StallController::class, 'deleteImage'])
            ->whereNumber('stall')->name('stalls.image.destroy');
        Route::delete('/stalls/{stall}', [StallController::class, 'destroy'])
            ->whereNumber('stall')->name('stalls.destroy');

        Route::get('/foods', [FoodController::class, 'index'])->name('foods.index');
        Route::get('/foods/create', [FoodController::class, 'create'])->name('foods.create');
        Route::post('/foods', [FoodController::class, 'store'])->name('foods.store');
        Route::get('/foods/{food}', [FoodController::class, 'show'])
            ->whereNumber('food')->name('foods.show');
        Route::get('/foods/{food}/edit', [FoodController::class, 'edit'])
            ->whereNumber('food')->name('foods.edit');
        Route::patch('/foods/{food}', [FoodController::class, 'update'])
            ->whereNumber('food')->name('foods.update');
        Route::patch('/foods/{food}/activate', [FoodController::class, 'activate'])
            ->whereNumber('food')->name('foods.activate');
        Route::patch('/foods/{food}/deactivate', [FoodController::class, 'deactivate'])
            ->whereNumber('food')->name('foods.deactivate');
        Route::patch('/foods/{food}/image', [FoodController::class, 'updateImage'])
            ->whereNumber('food')->name('foods.image.update');
        Route::delete('/foods/{food}/image', [FoodController::class, 'deleteImage'])
            ->whereNumber('food')->name('foods.image.destroy');
        Route::delete('/foods/{food}', [FoodController::class, 'destroy'])
            ->whereNumber('food')->name('foods.destroy');

        Route::get('/reviews', [AdminReviewController::class, 'index'])->name('reviews.index');
        Route::delete('/reviews/{review}', [AdminReviewController::class, 'destroy'])
            ->whereNumber('review')->name('reviews.destroy');

        Route::get('/social-media-records', [SocialMediaRecordController::class, 'index'])
            ->name('social-media-records.index');
        Route::get('/social-media/automation', [SocialMediaAutomationController::class, 'index'])
            ->name('social-media.automation.index');
        Route::get('/social-media/automation/create', [SocialMediaAutomationController::class, 'create'])
            ->name('social-media.automation.create');
        Route::post('/social-media/automation', [SocialMediaAutomationController::class, 'store'])
            ->name('social-media.automation.store');
        Route::post('/social-media/automation/proposals/{catalogImportProposal}/fetch-metadata', [SocialMediaAutomationController::class, 'fetchMetadata'])
            ->whereNumber('catalogImportProposal')
            ->name('social-media.automation.proposals.fetch-metadata');
        Route::post('/social-media/automation/proposals/{catalogImportProposal}/generate-suggestions', [SocialMediaAutomationController::class, 'generateSuggestions'])
            ->whereNumber('catalogImportProposal')
            ->name('social-media.automation.proposals.generate-suggestions');
        Route::post('/social-media/automation/proposals/{catalogImportProposal}/submit', [SocialMediaAutomationController::class, 'submit'])
            ->whereNumber('catalogImportProposal')
            ->name('social-media.automation.proposals.submit');
        Route::post('/social-media/automation/proposals/{catalogImportProposal}/reject', [SocialMediaAutomationController::class, 'reject'])
            ->whereNumber('catalogImportProposal')
            ->name('social-media.automation.proposals.reject');
        Route::post('/social-media/automation/proposals/{catalogImportProposal}/approve-import', [SocialMediaAutomationController::class, 'approveAndImport'])
            ->whereNumber('catalogImportProposal')
            ->name('social-media.automation.proposals.approve-import');
        Route::patch('/social-media/automation/proposals/{catalogImportProposal}/market/{proposalMarket}', [SocialMediaAutomationController::class, 'updateSuggestionMarket'])
            ->whereNumber('catalogImportProposal')->whereNumber('proposalMarket')
            ->name('social-media.automation.proposals.market.update');
        Route::patch('/social-media/automation/proposals/{catalogImportProposal}/operating-days/{proposalOperatingDay}', [SocialMediaAutomationController::class, 'updateSuggestionOperatingDay'])
            ->whereNumber('catalogImportProposal')->whereNumber('proposalOperatingDay')
            ->name('social-media.automation.proposals.operating-days.update');
        Route::delete('/social-media/automation/proposals/{catalogImportProposal}/operating-days/{proposalOperatingDay}', [SocialMediaAutomationController::class, 'destroySuggestionOperatingDay'])
            ->whereNumber('catalogImportProposal')->whereNumber('proposalOperatingDay')
            ->name('social-media.automation.proposals.operating-days.destroy');
        Route::patch('/social-media/automation/proposals/{catalogImportProposal}/stalls/{proposalStall}', [SocialMediaAutomationController::class, 'updateSuggestionStall'])
            ->whereNumber('catalogImportProposal')->whereNumber('proposalStall')
            ->name('social-media.automation.proposals.stalls.update');
        Route::delete('/social-media/automation/proposals/{catalogImportProposal}/stalls/{proposalStall}', [SocialMediaAutomationController::class, 'destroySuggestionStall'])
            ->whereNumber('catalogImportProposal')->whereNumber('proposalStall')
            ->name('social-media.automation.proposals.stalls.destroy');
        Route::patch('/social-media/automation/proposals/{catalogImportProposal}/foods/{proposalFood}', [SocialMediaAutomationController::class, 'updateSuggestionFood'])
            ->whereNumber('catalogImportProposal')->whereNumber('proposalFood')
            ->name('social-media.automation.proposals.foods.update');
        Route::delete('/social-media/automation/proposals/{catalogImportProposal}/foods/{proposalFood}', [SocialMediaAutomationController::class, 'destroySuggestionFood'])
            ->whereNumber('catalogImportProposal')->whereNumber('proposalFood')
            ->name('social-media.automation.proposals.foods.destroy');
        Route::get('/social-media/automation/{catalogImportProposal}', [SocialMediaAutomationController::class, 'show'])
            ->whereNumber('catalogImportProposal')
            ->name('social-media.automation.show');
        Route::get('/social-media/extract', [SocialMediaExtractionController::class, 'create'])
            ->name('social-media.extract.create');
        Route::post('/social-media/extract', [SocialMediaExtractionController::class, 'extract'])
            ->name('social-media.extract.extract');
        Route::get('/social-media/extract/review', [SocialMediaExtractionController::class, 'review'])
            ->name('social-media.extract.review');
        Route::post('/social-media/extract/store', [SocialMediaExtractionController::class, 'store'])
            ->name('social-media.extract.store');
        Route::get('/social-media-records/create', [SocialMediaRecordController::class, 'create'])
            ->name('social-media-records.create');
        Route::post('/social-media-records', [SocialMediaRecordController::class, 'store'])
            ->name('social-media-records.store');
        Route::get('/social-media-records/{socialMediaRecord}/edit', [SocialMediaRecordController::class, 'edit'])
            ->whereNumber('socialMediaRecord')->name('social-media-records.edit');
        Route::patch('/social-media-records/{socialMediaRecord}', [SocialMediaRecordController::class, 'update'])
            ->whereNumber('socialMediaRecord')->name('social-media-records.update');
        Route::delete('/social-media-records/{socialMediaRecord}', [SocialMediaRecordController::class, 'destroy'])
            ->whereNumber('socialMediaRecord')->name('social-media-records.destroy');
        Route::patch('/social-media-records/{socialMediaRecord}/moderate', [SocialMediaRecordController::class, 'moderate'])
            ->whereNumber('socialMediaRecord')->name('social-media-records.moderate');

        Route::get('/users', [UserManagementController::class, 'index'])
            ->name('users.index');
        Route::get('/users/{user}', [UserManagementController::class, 'show'])
            ->whereNumber('user')
            ->name('users.show');
        Route::patch('/users/{user}/status', [UserManagementController::class, 'updateStatus'])
            ->whereNumber('user')
            ->name('users.status.update');
        Route::patch('/users/{user}/promote', [UserManagementController::class, 'promote'])
            ->middleware('role:super_admin')->whereNumber('user')->name('users.promote');
        Route::patch('/users/{user}/demote', [UserManagementController::class, 'demote'])
            ->middleware('role:super_admin')->whereNumber('user')->name('users.demote');
    });
});
