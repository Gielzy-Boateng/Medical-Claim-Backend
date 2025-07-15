<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PostController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


//!!API_ENDPOINTS - All API endpoints are defined here
Route::apiResource('post', PostController::class);

//!!REGISTER ENDPOINT - Register a new user
Route::post('/register', [AuthController::class, 'register']);

//!!LOGIN ENDPOINT - Login a user
Route::post('/login', [AuthController::class, 'login']);

//!!LOGOUT A USER
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

// Route::post('/set-role', [AuthController::class, 'setRole'])->middleware('auth:sanctum');
//!!ADMIN_ASSIGN_ROLE - Only HR/Admin can assign roles to others
Route::post('/admin/assign-role', [AuthController::class, 'assignRole'])->middleware('auth:sanctum');

//!!MY_POSTS - Get all posts for the authenticated user
Route::get('/my-posts', [PostController::class, 'myPosts'])->middleware('auth:sanctum');

//!!APPROVE A CLAIM
Route::post('/claims/{post}/approve', [PostController::class, 'approve'])->middleware('auth:sanctum');

//!!REJECT A CLAIM
Route::post('/claims/{post}/reject', [PostController::class, 'reject'])->middleware('auth:sanctum');

//!!SUPERVISOR_CLAIMS - Get all claims for the supervisor
Route::get('/supervisor/claims', [PostController::class, 'supervisorClaims'])->middleware(['auth:sanctum', 'role:supervisor']);

//!!MANAGER_CLAIMS - Get all claims for the manager
Route::get('/manager/claims', [PostController::class, 'managerClaims'])->middleware(['auth:sanctum', 'role:manager']);

//!!HR_CLAIMS - Get all claims for the hr
Route::get('/hr/claims', [PostController::class, 'hrClaims'])->middleware(['auth:sanctum', 'role:hr']);

//!!ACCOUNT_CLAIMS - Get all claims for the account
Route::get('/account/claims', [PostController::class, 'accountClaims'])->middleware(['auth:sanctum', 'role:account']);

//!!PENDING_CLAIMS_BY_STAGE - Get all pending claims by stage
Route::get('/claims/pending/{stage}', [PostController::class, 'pendingClaimsByStage'])->middleware('auth:sanctum');

//!!ALL_SUPERVISOR_CLAIMS - Get all claims for the supervisor
Route::get('/supervisor/all-claims', [PostController::class, 'allSupervisorClaims'])->middleware(['auth:sanctum', 'role:supervisor']);

//!!ALL_MANAGER_CLAIMS - Get all claims for the manager
Route::get('/manager/all-claims', [PostController::class, 'allManagerClaims'])->middleware(['auth:sanctum', 'role:manager']);

//!!ALL_HR_CLAIMS - Get all claims for the hr
Route::get('/hr/all-claims', [PostController::class, 'allHrClaims'])->middleware(['auth:sanctum', 'role:hr']);

//!!ALL_ACCOUNT_CLAIMS - Get all claims for the account
Route::get('/account/all-claims', [PostController::class, 'allAccountClaims'])->middleware(['auth:sanctum', 'role:account']);

//!!MY_HANDLED_CLAIMS - Get all handled claims for the authenticated user
Route::get('/my-handled-claims', [\App\Http\Controllers\PostController::class, 'myHandledClaims'])->middleware('auth:sanctum');

//!!MY_CLAIMS_GROUPED - Get all claims for the authenticated user grouped by stage
Route::get('/my-claims-grouped', [\App\Http\Controllers\PostController::class, 'myClaimsGrouped'])->middleware('auth:sanctum');

//!!USERS - Get all users for the hr
Route::get('/users', [AuthController::class, 'getAllUsers'])->middleware(['auth:sanctum', 'role:hr']);

//!!GET_ALL_SUPERVISORS - Get all users with supervisor role
Route::get('/supervisors', [AuthController::class, 'getAllSupervisors'])->middleware('auth:sanctum');


//!!PRODUCTION_TEST
Route::get('/ping', function () {
    return response()->json(['message' => 'pong']);
});
