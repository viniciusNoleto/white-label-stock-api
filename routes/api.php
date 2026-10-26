<?php

use App\Base\Traits\Response;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Inventory\InventoryItemController;
use App\Http\Controllers\Inventory\ItemCategoryController;
use App\Http\Controllers\Inventory\StorageController;
use App\Http\Controllers\Inventory\UnitController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->name('auth.login');
    Route::post('logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('me', [AuthController::class, 'me'])->name('auth.me');
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

    Route::prefix('inventory-items')->group(function () {
        Route::get('', [InventoryItemController::class, 'index'])->name('inventory-items.index');
        Route::post('', [InventoryItemController::class, 'store'])->name('inventory-items.store');
        Route::prefix('{inventory_item_id}')->group(function () {
            Route::patch('', [InventoryItemController::class, 'update'])->name('inventory-items.update');
            Route::post('build', [InventoryItemController::class, 'build'])->name('inventory-items.build');
        });
    });

    Route::fallback(fn() => Response::errorResponse('messages.not_found', 404));
});
