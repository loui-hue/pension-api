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
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\PortalController;
// Rutas públicas
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');   // 10 intentos por minuto
Route::get('/ping', [PortalController::class, 'ping']);
// Portal en la nube: recibe la copia de la pensión (protegida con PORTAL_SYNC_TOKEN).
Route::post('/portal/sync', [PortalController::class, 'sync']);

// Rutas protegidas
// password.changed: con contraseña temporal solo se permite /me, /me/password y /logout.
// role:x,y: solo esos roles (el administrador siempre puede). Sin "role": cualquier usuario con sesión.
Route::middleware(['auth:sanctum', 'password.changed'])->group(function () {

    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/me/password', [AuthController::class, 'changePassword']);

    // Usuarios: el personal busca comensales; solo el administrador gestiona cuentas.
    Route::get('users', [UserController::class, 'index'])->middleware('role:mesero,caja');
    Route::apiResource('users', UserController::class)->except('index')->middleware('role:administrador');
    Route::middleware('role:mesero,caja')->group(function () {
        Route::post('customers', [UserController::class, 'storeCustomer']);
        Route::post('customers/{id}/reset-password', [UserController::class, 'resetCustomerPassword']);
    });

    // Menú: todos lo ven (el cliente arma su pedido); solo el administrador lo modifica.
    Route::apiResource('categories', CategoryController::class)->only(['index', 'show']);
    Route::apiResource('products', ProductController::class)->only(['index', 'show']);
    Route::middleware('role:administrador')->group(function () {
        Route::apiResource('categories', CategoryController::class)->except(['index', 'show']);
        Route::apiResource('products', ProductController::class)->except(['index', 'show']);
    });

    // Mesas: todos las ven (para elegir mesa); el personal de sala cambia su estado.
    Route::apiResource('tables', TableController::class)->only(['index', 'show']);
    Route::apiResource('tables', TableController::class)->except(['index', 'show'])->middleware('role:administrador');
    Route::patch('tables/{id}/status', [TableController::class, 'updateStatus'])->middleware('role:mesero,caja');

    // Pedidos: el cliente crea los suyos y ve "Mis pedidos"; el personal ve y atiende todos.
    Route::get('orders/my-orders', [OrderController::class, 'myOrders']);
    Route::post('orders', [OrderController::class, 'store']);
    Route::middleware('role:mesero,cocina,caja')->group(function () {
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{order}', [OrderController::class, 'show']);
        Route::patch('orders/{id}/status', [OrderController::class, 'updateStatus']);
    });
    Route::match(['put', 'patch'], 'orders/{order}', [OrderController::class, 'update'])->middleware('role:mesero');
    Route::delete('orders/{order}', [OrderController::class, 'destroy'])->middleware('role:administrador');

    // Pagos: caja cobra y confirma; el administrador configura los métodos (QR, cuenta).
    Route::middleware('role:caja')->group(function () {
        Route::get('payment-methods', [PaymentController::class, 'methods']);
        Route::get('payment-methods/{id}/qr', [PaymentController::class, 'qrImage']);
        Route::get('payments', [PaymentController::class, 'index']);
        Route::post('payments', [PaymentController::class, 'store']);
        Route::get('payments/{payment}', [PaymentController::class, 'show']);
        Route::patch('payments/{id}/confirm', [PaymentController::class, 'confirm']);
        Route::patch('payments/{id}/reject', [PaymentController::class, 'reject']);
    });
    Route::middleware('role:administrador')->group(function () {
        Route::post('payment-methods/{id}', [PaymentController::class, 'updateMethod']); // multipart (QR)
        Route::match(['put', 'patch'], 'payments/{payment}', [PaymentController::class, 'update']);
        Route::delete('payments/{payment}', [PaymentController::class, 'destroy']);
    });

    // Fidelización: cada usuario consulta lo suyo; solo el administrador otorga XP o insignias a mano.
    Route::get('/loyalty', [LoyaltyController::class, 'index']);
    Route::post('/loyalty/streak', [LoyaltyController::class, 'streak']);   // solo consulta la racha
    Route::post('/loyalty/points', [LoyaltyController::class, 'addPoints'])->middleware('role:administrador');

    // Insignias
    Route::get('/badges', [BadgeController::class, 'index']);
    Route::get('/badges/user', [BadgeController::class, 'userBadges']);
    Route::post('/badges/assign', [BadgeController::class, 'assignBadge'])->middleware('role:administrador');

    // Retos: el comensal se une; solo el administrador crea retos o registra su avance.
    Route::get('/challenges', [ChallengeController::class, 'index']);
    Route::get('/challenges/user', [ChallengeController::class, 'userChallenges']);
    Route::post('/challenges/join', [ChallengeController::class, 'join'])->middleware('role:cliente');
    Route::middleware('role:administrador')->group(function () {
        Route::post('/challenges', [ChallengeController::class, 'store']);
        Route::patch('/challenges/{id}/progress', [ChallengeController::class, 'updateProgress']);
    });

    // Reportes del Dashboard
    Route::get('/reports/dashboard', [ReportController::class, 'dashboard'])->middleware('role:administrador');
});
