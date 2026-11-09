<?php

use App\Base\Traits\Response;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Delivery\DeliveryPackageController;
use App\Http\Controllers\Inventory\ItemController;
use App\Http\Controllers\Inventory\ItemCategoryController;
use App\Http\Controllers\Inventory\ProductController;
use App\Http\Controllers\Inventory\StockController;
use App\Http\Controllers\Inventory\StorageController;
use App\Http\Controllers\Inventory\UnitController;
use App\Http\Controllers\User\RegistrationRequestController;
use App\Http\Controllers\User\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->name('auth.login');
    Route::post('register', [AuthController::class, 'register'])->name('auth.register');
    Route::post('logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('me', [AuthController::class, 'me'])->middleware('auth')->name('auth.me');
    Route::prefix('token')->group(function () {
        Route::post('refresh', [AuthController::class, 'refreshToken'])->name('auth.token.refresh');
    });
});

Route::middleware('auth')->group(function () {
    Route::prefix('units')->group(function () {
        Route::get('', [UnitController::class, 'index'])->name('units.index');
        Route::post('', [UnitController::class, 'store'])->name('units.store');
        Route::put('{unit_id}', [UnitController::class, 'update'])->name('units.update');
        Route::delete('{unit_id}', [UnitController::class, 'destroy'])->name('units.destroy');
    });

    Route::prefix('item-categories')->group(function () {
        Route::get('', [ItemCategoryController::class, 'index'])->name('item-categories.index');
        Route::post('', [ItemCategoryController::class, 'store'])->name('item-categories.store');
        Route::put('{item_category_id}', [ItemCategoryController::class, 'update'])->name('item-categories.update');
        Route::delete('{item_category_id}', [ItemCategoryController::class, 'destroy'])->name('item-categories.destroy');
    });

    Route::prefix('storages')->group(function () {
        Route::get('', [StorageController::class, 'index'])->name('storages.index');
        Route::post('', [StorageController::class, 'store'])->name('storages.store');
        Route::put('{storage_id}', [StorageController::class, 'update'])->name('storages.update');
        Route::delete('{storage_id}', [StorageController::class, 'destroy'])->name('storages.destroy');
    });

    Route::prefix('products')->group(function () {
        Route::get('', [ProductController::class, 'index'])->name('products.index');
        Route::post('', [ProductController::class, 'store'])->name('products.store');
        Route::put('{product_id}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('{product_id}', [ProductController::class, 'destroy'])->name('products.destroy');
    });

    Route::prefix('items')->group(function () {
        Route::get('', [ItemController::class, 'index'])->name('items.index');
        Route::post('', [ItemController::class, 'store'])->name('items.store');
        Route::patch('{item_id}', [ItemController::class, 'update'])->name('items.update');
        Route::delete('{item_id}', [ItemController::class, 'destroy'])->name('items.destroy');
    });

    Route::get('stock', [StockController::class, 'index'])->name('stock.index');

    Route::prefix('delivery-packages')->group(function () {
        Route::get('', [DeliveryPackageController::class, 'index'])->name('delivery-packages.index');
        Route::post('', [DeliveryPackageController::class, 'store'])->name('delivery-packages.store');
        Route::prefix('{delivery_package_id}')->group(function () {
            Route::get('', [DeliveryPackageController::class, 'show'])->name('delivery-packages.show');
            Route::put('', [DeliveryPackageController::class, 'update'])->name('delivery-packages.update');
            Route::delete('', [DeliveryPackageController::class, 'destroy'])->name('delivery-packages.destroy');
            Route::post('deliver', [DeliveryPackageController::class, 'deliver'])->name('delivery-packages.deliver');
        });
    });

    // Administração: só admins (403 para usuário comum).
    Route::middleware('admin')->group(function () {
        Route::prefix('users')->group(function () {
            Route::get('', [UserController::class, 'index'])->name('users.index');
            Route::delete('{user_id}', [UserController::class, 'destroy'])->name('users.destroy');
        });

        Route::prefix('registration-requests')->group(function () {
            Route::get('', [RegistrationRequestController::class, 'index'])->name('registration-requests.index');
            Route::prefix('{registration_request_id}')->group(function () {
                Route::post('approve', [RegistrationRequestController::class, 'approve'])->name('registration-requests.approve');
                Route::delete('', [RegistrationRequestController::class, 'destroy'])->name('registration-requests.destroy');
            });
        });
    });

    Route::fallback(fn() => Response::errorResponse('messages.not_found', 404));
});
