<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LostItemController;
use App\Http\Controllers\Api\FoundItemController;
use App\Http\Controllers\Api\ClaimController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AiMatchController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\FcmController;
use App\Http\Controllers\Api\UserManagementController;
use App\Http\Controllers\Api\SystemStatsController;
use App\Http\Controllers\Api\CaseTrailController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SupportController;


Route::prefix('auth')->group(function () {
    Route::post('/super-admin/login', [AuthController::class, 'superAdminLogin']);
    Route::post('/admin/login', [AuthController::class, 'adminLogin']);
    Route::post('/student/login', [AuthController::class, 'studentLogin']);
    Route::post('/student/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/student/resend-otp', [AuthController::class, 'resendOtp']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });

});

Route::middleware('auth:sanctum')->group(function () {

    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'show']);
        Route::put('/', [ProfileController::class, 'update']);
        Route::post('/change-password', [ProfileController::class, 'changePassword']);
        Route::post('/request-password-change', [ProfileController::class, 'requestPasswordChange']);
        Route::post('/set-new-password', [ProfileController::class, 'setNewPassword']);
        Route::post('/verify-id', [ProfileController::class, 'verifyId']);
    });

    Route::prefix('lost-items')->group(function () {
        Route::get('/', [LostItemController::class, 'index']);
        Route::get('/my-reports', [LostItemController::class, 'myReports']);
        Route::post('/', [LostItemController::class, 'store']);
        Route::get('/{id}', [LostItemController::class, 'show']);
        Route::put('/{id}', [LostItemController::class, 'update']);
        Route::delete('/{id}', [LostItemController::class, 'destroy']);
    });

    Route::prefix('found-items')->middleware('admin.privilege:item_management')->group(function () {
        Route::get('/', [FoundItemController::class, 'index']);
        Route::post('/', [FoundItemController::class, 'store']);
        Route::get('/{id}', [FoundItemController::class, 'show']);
        Route::put('/{id}', [FoundItemController::class, 'update']);
        Route::delete('/{id}', [FoundItemController::class, 'destroy']);
    });

    Route::prefix('claims')->group(function () {
        Route::get('/my-claims', [ClaimController::class, 'myClaims']);
        Route::post('/', [ClaimController::class, 'store']);
        Route::get('/{id}/questions', [ClaimController::class, 'getQuestions']);
        Route::post('/{id}/answers', [ClaimController::class, 'submitAnswers']);
        Route::post('/{id}/appeal', [ClaimController::class, 'submitAppeal']);

        Route::middleware('admin.privilege:claim_verification')->group(function () {
            Route::get('/', [ClaimController::class, 'index']);
            Route::post('/{id}/approve', [ClaimController::class, 'approve']);
            Route::post('/{id}/reject', [ClaimController::class, 'reject']);
            Route::post('/{id}/collected', [ClaimController::class, 'markCollected']);
            Route::get('/appeals', [ClaimController::class, 'pendingAppeals']);
            Route::post('/{id}/resolve-appeal', [ClaimController::class, 'resolveAppeal']);
        });
    });

    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
    });

    Route::prefix('audit-logs')->middleware('admin.privilege:digital_records')->group(function () {
        Route::get('/', [AuditLogController::class, 'index']);
        Route::get('/{type}/{id}', [AuditLogController::class, 'byCase']);
    });

    Route::prefix('ai-matches')->group(function () {
        Route::get('/', [AiMatchController::class, 'index']);
        Route::get('/my-matches', [AiMatchController::class, 'myMatches']);
        Route::get('/{id}', [AiMatchController::class, 'show']);
        Route::post('/', [AiMatchController::class, 'store']);
        Route::post('/{id}/confirm', [AiMatchController::class, 'confirm']);
        Route::post('/{id}/reject', [AiMatchController::class, 'reject']);
        Route::get('/{id}/reveal', [AiMatchController::class, 'myMatchedItem']);
    });

    Route::prefix('locations')->middleware('admin.privilege:location_analytics')->group(function () {
        Route::get('/', [LocationController::class, 'index']);
        Route::get('/hotspots', [LocationController::class, 'hotspots']);
        Route::post('/', [LocationController::class, 'store']);
    });

    Route::prefix('upload')->group(function () {
        Route::post('/image', [UploadController::class, 'uploadImage']);
        Route::delete('/image', [UploadController::class, 'deleteImage']);
        Route::post('/analyze-existing', [UploadController::class, 'analyzeExisting']);
    });

    Route::prefix('users')->group(function () {
        Route::get('/', [UserManagementController::class, 'index']);
        Route::post('/', [UserManagementController::class, 'store']);
        Route::put('/{id}', [UserManagementController::class, 'update']);
        Route::post('/{id}/revoke', [UserManagementController::class, 'revoke']);
        Route::post('/{id}/restore', [UserManagementController::class, 'restore']);
        Route::post('/{id}/approve-password-change', [UserManagementController::class, 'approvePasswordChange']);
        Route::post('/{id}/toggle-restriction', [UserManagementController::class, 'toggleRestriction']);
    });

    Route::prefix('case-trail')->middleware('admin.privilege:digital_records')->group(function () {
        Route::get('/', [CaseTrailController::class, 'index']);
        Route::get('/{id}', [CaseTrailController::class, 'show']);
    });

    Route::prefix('support')->group(function () {
        Route::post('/', [SupportController::class, 'store']);
        Route::get('/my-messages', [SupportController::class, 'myMessages']);

        Route::middleware('admin.privilege:support_inbox')->group(function () {
            Route::get('/', [SupportController::class, 'index']);
            Route::post('/{id}/read', [SupportController::class, 'markAsRead']);
            Route::get('/{id}/thread', [SupportController::class, 'getThread']);
            Route::post('/{id}/reply', [SupportController::class, 'reply']);
        });
    });

    Route::post('/fcm/update-token', [FcmController::class, 'updateToken']);
    Route::get('/system-stats', [SystemStatsController::class, 'index']);
    Route::get('/system-settings', [SystemStatsController::class, 'getSettings']);
    Route::put('/system-settings', [SystemStatsController::class, 'updateSettings']);
    Route::post('/system/backup', [SystemStatsController::class, 'backupNow']);
    Route::get('/system/backups', [SystemStatsController::class, 'listBackups']);
    Route::get('/system/backups/{filename}', [SystemStatsController::class, 'downloadBackup']);
    Route::get('/system/maintenance-mode', [SystemStatsController::class, 'getMaintenanceMode']);
    Route::post('/system/maintenance-mode', [SystemStatsController::class, 'toggleMaintenanceMode']);

});
