<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\TableController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\LoyaltyController;
use App\Http\Controllers\Api\BadgeController;
use App\Http\Controllers\Api\ChallengeController;
use App\Http\Controllers\Api\UserController;
// Rutas públicas
Route::post('/login', [AuthController::class, 'login']);

// Rutas protegidas
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Usuarios
    Route::apiResource('users', UserController::class);

    // Categorías
    Route::apiResource('categories', CategoryController::class);

    // Productos
    Route::apiResource('products', ProductController::class);

    // Mesas
    Route::apiResource('tables', TableController::class);
    Route::patch('tables/{id}/status', [TableController::class, 'updateStatus']);

    // Pedidos
    Route::get('orders/my-orders', [OrderController::class, 'myOrders']);
    Route::apiResource('orders', OrderController::class);
    Route::patch('orders/{id}/status', [OrderController::class, 'updateStatus']);

    // Pagos
    Route::apiResource('payments', PaymentController::class);

    // Fidelización
    Route::get('/loyalty', [LoyaltyController::class, 'index']);
    Route::post('/loyalty/points', [LoyaltyController::class, 'addPoints']);
    Route::post('/loyalty/streak', [LoyaltyController::class, 'streak']);

    // Insignias
    Route::get('/badges', [BadgeController::class, 'index']);
    Route::get('/badges/user', [BadgeController::class, 'userBadges']);
    Route::post('/badges/assign', [BadgeController::class, 'assignBadge']);

    // Retos
    Route::get('/challenges', [ChallengeController::class, 'index']);
    Route::get('/challenges/user', [ChallengeController::class, 'userChallenges']);
    Route::post('/challenges', [ChallengeController::class, 'store']);
    Route::post('/challenges/join', [ChallengeController::class, 'join']);
    Route::patch('/challenges/{id}/progress', [ChallengeController::class, 'updateProgress']);
});
