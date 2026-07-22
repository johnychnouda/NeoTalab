<?php

use App\Http\Controllers\Api\V1\WhatsAppWebhookController;
use App\Http\Controllers\Api\V1\Admin\MerchantController as AdminMerchantController;
use App\Http\Controllers\Api\V1\Admin\OnboardingController as AdminOnboardingController;
use App\Http\Controllers\Api\V1\Admin\PlatformSettingsController;
use App\Http\Controllers\Api\V1\Admin\WhatsAppEmbeddedSignupController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DriverController;
use App\Http\Controllers\Api\V1\MerchantController;
use App\Http\Controllers\Api\V1\ModifierController;
use App\Http\Controllers\Api\V1\ModifierGroupController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductVariantController;
use App\Http\Controllers\Api\V1\PublicOnboardingController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\UserController;
use App\Support\Roles;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // ── Public ────────────────────────────────────────────────
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('onboarding/apply', [PublicOnboardingController::class, 'store']);

    Route::get('webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify']);
    Route::post('webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive']);

    // ── Authenticated ─────────────────────────────────────────
    Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::patch('auth/password', [AuthController::class, 'updatePassword']);
        Route::post('auth/set-initial-password', [AuthController::class, 'setInitialPassword']);
        Route::post('auth/revoke-other-sessions', [AuthController::class, 'revokeOtherSessions']);

        $merchantRoles = Roles::MERCHANT_OWNER.','.Roles::MERCHANT_ADMIN.','.Roles::MERCHANT_STAFF;
        $managerRoles = Roles::MERCHANT_OWNER.','.Roles::MERCHANT_ADMIN;

        Route::get('merchant', [MerchantController::class, 'show'])->middleware("role:{$merchantRoles}");
        Route::patch('merchant', [MerchantController::class, 'update'])->middleware("role:{$managerRoles}");

        Route::middleware("role:{$managerRoles}")->group(function () {
            Route::get('users', [UserController::class, 'index']);
            Route::post('users', [UserController::class, 'store']);
            Route::patch('users/{user}', [UserController::class, 'update']);
            Route::delete('users/{user}', [UserController::class, 'destroy']);
        });

        // ── Merchant settings ─────────────────────────────────
        Route::middleware("role:{$merchantRoles}")->prefix('settings')->group(function () use ($managerRoles) {
            Route::get('profile', [SettingsController::class, 'profile']);
            Route::patch('profile', [SettingsController::class, 'updateProfile'])->middleware("role:{$managerRoles}");
            Route::patch('mode', [SettingsController::class, 'updateMode'])->middleware("role:{$managerRoles}");
            Route::get('hours', [SettingsController::class, 'hours']);
            Route::put('hours', [SettingsController::class, 'replaceHours'])->middleware("role:{$managerRoles}");
            Route::get('zones', [SettingsController::class, 'zones']);
            Route::post('zones', [SettingsController::class, 'storeZone'])->middleware("role:{$managerRoles}");
            Route::delete('zones/{zone}', [SettingsController::class, 'destroyZone'])->middleware("role:{$managerRoles}");
        });

        // ── Orders ────────────────────────────────────────────
        Route::middleware("role:{$merchantRoles}")->prefix('orders')->group(function () {
            Route::get('live', [OrderController::class, 'live']);
            Route::get('/', [OrderController::class, 'index']);
            Route::post('{order}/{action}', [OrderController::class, 'action'])
                ->where('action', 'accept|reject|cancel|preparing|ready');
        });

        // ── Customers ─────────────────────────────────────────
        Route::middleware("role:{$merchantRoles}")->prefix('customers')->group(function () use ($managerRoles) {
            Route::get('/', [CustomerController::class, 'index']);
            Route::post('{customer}/block', [CustomerController::class, 'block'])->middleware("role:{$managerRoles}");
            Route::post('{customer}/unblock', [CustomerController::class, 'unblock'])->middleware("role:{$managerRoles}");
        });

        // ── Drivers ───────────────────────────────────────────
        Route::middleware("role:{$merchantRoles}")->prefix('drivers')->group(function () use ($managerRoles) {
            Route::get('/', [DriverController::class, 'index']);
            Route::post('/', [DriverController::class, 'store'])->middleware("role:{$managerRoles}");
            Route::post('{driver}/status', [DriverController::class, 'setStatus'])->middleware("role:{$managerRoles}");
            Route::delete('{driver}', [DriverController::class, 'destroy'])->middleware("role:{$managerRoles}");
        });

        // ── Analytics ─────────────────────────────────────────
        Route::middleware("role:{$merchantRoles}")->prefix('analytics')->group(function () {
            Route::get('dashboard', [AnalyticsController::class, 'dashboard']);
        });

        // ── Catalog ───────────────────────────────────────────
        Route::middleware("role:{$merchantRoles}")->group(function () {
            Route::get('categories', [CategoryController::class, 'index']);
            Route::get('products', [ProductController::class, 'index']);
            Route::get('products/{product}', [ProductController::class, 'show']);
        });

        Route::middleware("role:{$merchantRoles}")->group(function () {
            Route::post('conversations', [ConversationController::class, 'store']);
            Route::get('conversations/{conversation}', [ConversationController::class, 'show']);
            Route::post('conversations/{conversation}/turns', [ConversationController::class, 'turn']);
        });

        Route::middleware("role:{$managerRoles}")->group(function () {
            Route::post('categories', [CategoryController::class, 'store']);
            Route::patch('categories/{category}', [CategoryController::class, 'update']);
            Route::delete('categories/{category}', [CategoryController::class, 'destroy']);

            Route::post('products', [ProductController::class, 'store']);
            Route::patch('products/{product}', [ProductController::class, 'update']);
            Route::delete('products/{product}', [ProductController::class, 'destroy']);

            Route::post('products/{product}/variants', [ProductVariantController::class, 'store']);
            Route::patch('variants/{variant}', [ProductVariantController::class, 'update']);
            Route::delete('variants/{variant}', [ProductVariantController::class, 'destroy']);

            Route::post('products/{product}/modifier-groups', [ModifierGroupController::class, 'store']);
            Route::patch('modifier-groups/{group}', [ModifierGroupController::class, 'update']);
            Route::delete('modifier-groups/{group}', [ModifierGroupController::class, 'destroy']);

            Route::post('modifier-groups/{group}/modifiers', [ModifierController::class, 'store']);
            Route::patch('modifiers/{modifier}', [ModifierController::class, 'update']);
            Route::delete('modifiers/{modifier}', [ModifierController::class, 'destroy']);
        });

        // ── Platform admin (cross-tenant) ─────────────────────
        Route::middleware('role:'.Roles::PLATFORM_SUPER_ADMIN)->prefix('admin')->group(function () {
            Route::get('onboarding', [AdminOnboardingController::class, 'index']);
            Route::delete('onboarding/{id}', [AdminOnboardingController::class, 'destroy']);
            Route::post('onboarding/{id}/approve', [AdminOnboardingController::class, 'approve']);

            Route::get('merchants', [AdminMerchantController::class, 'index']);
            Route::post('merchants', [AdminMerchantController::class, 'storeFromOwnerPortal']);
            Route::post('merchants/provision', [AdminMerchantController::class, 'store']);
            Route::post('broadcast', [AdminMerchantController::class, 'broadcast']);
            Route::get('merchants/{id}', [AdminMerchantController::class, 'show']);
            Route::patch('merchants/{id}', [AdminMerchantController::class, 'update']);
            Route::delete('merchants/{id}', [AdminMerchantController::class, 'destroy']);
            Route::post('merchants/{id}/impersonate', [AdminMerchantController::class, 'impersonate']);
            Route::get('merchants/{id}/payments', [AdminMerchantController::class, 'payments']);
            Route::patch('merchants/{id}/bot', [AdminMerchantController::class, 'updateBot']);
            Route::post('merchants/{id}/bot/test', [AdminMerchantController::class, 'testBot']);
            Route::post('merchants/{id}/bot/restart', [AdminMerchantController::class, 'restartBot']);
            Route::post('merchants/{id}/bot/register-webhook', [AdminMerchantController::class, 'registerWebhook']);
            Route::post('merchants/{id}/welcome', [AdminMerchantController::class, 'sendWelcome']);
            Route::get('whatsapp/embedded-signup/config', [WhatsAppEmbeddedSignupController::class, 'config']);
            Route::post('merchants/{id}/whatsapp/embedded-signup', [WhatsAppEmbeddedSignupController::class, 'connect']);
            Route::get('platform-settings', [PlatformSettingsController::class, 'show']);
            Route::get('platform-settings/whatsapp-access-token', [PlatformSettingsController::class, 'revealWhatsAppAccessToken']);
            Route::patch('platform-settings', [PlatformSettingsController::class, 'update']);
        });
    });
});
