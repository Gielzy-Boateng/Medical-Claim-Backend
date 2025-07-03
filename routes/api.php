<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PostController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


// Route::get('/',function (Request $request){
//     return 'API';
// });

Route::apiResource('post', PostController::class);

Route::post('/register', [AuthController::class, 'register']);

Route::post('/login', [AuthController::class, 'login']);

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

Route::post('/set-role', [AuthController::class, 'setRole'])->middleware('auth:sanctum');

Route::get('/my-posts', [PostController::class, 'myPosts'])->middleware('auth:sanctum');

Route::post('/claims/{post}/approve', [PostController::class, 'approve'])->middleware('auth:sanctum');

Route::post('/claims/{post}/reject', [PostController::class, 'reject'])->middleware('auth:sanctum');

Route::get('/supervisor/claims', [PostController::class, 'supervisorClaims'])->middleware('auth:sanctum');

Route::get('/manager/claims', [PostController::class, 'managerClaims'])->middleware('auth:sanctum');

Route::get('/hr/claims', [PostController::class, 'hrClaims'])->middleware('auth:sanctum');

Route::get('/account/claims', [PostController::class, 'accountClaims'])->middleware('auth:sanctum');

Route::get('/claims/pending/{stage}', [PostController::class, 'pendingClaimsByStage'])->middleware('auth:sanctum');

Route::get('/supervisor/all-claims', [PostController::class, 'allSupervisorClaims'])->middleware('auth:sanctum');

Route::get('/manager/all-claims', [PostController::class, 'allManagerClaims'])->middleware('auth:sanctum');

Route::get('/hr/all-claims', [PostController::class, 'allHrClaims'])->middleware('auth:sanctum');

Route::get('/account/all-claims', [PostController::class, 'allAccountClaims'])->middleware('auth:sanctum');

Route::get('/my-handled-claims', [\App\Http\Controllers\PostController::class, 'myHandledClaims'])->middleware('auth:sanctum');
