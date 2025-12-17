<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\ConsultantController;
use App\Http\Controllers\Api\V1\ConsultationController;
use App\Http\Controllers\Api\V1\TokenController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\QuestionnaireController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\GdprController;
use App\Http\Controllers\Api\V1\TechnologyController;
use App\Http\Controllers\Api\V1\ProblemController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group.
|
*/

// API Version 1
Route::prefix('v1')->group(function () {
    
    // Public routes
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    Route::post('/verify-email/{id}/{hash}', [AuthController::class, 'verifyEmail'])->name('verification.verify');
    Route::post('/resend-verification', [AuthController::class, 'resendVerification']);
    
    // Public token packages (for pricing page)
    Route::get('/token-packages', [TokenController::class, 'index']);
    
    // Public subscription plans
    Route::get('/subscription-plans', [SubscriptionController::class, 'index']);
    
    // Public technologies
    Route::get('/technologies', [TechnologyController::class, 'index']);
    Route::get('/technologies/search', [TechnologyController::class, 'search']);
    
    // Protected routes
    Route::middleware('auth:sanctum')->group(function () {
        
        // Auth routes
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/user', [AuthController::class, 'user']);
        Route::post('/refresh-token', [AuthController::class, 'refreshToken']);
        
        // Two-factor authentication
        Route::post('/2fa/enable', [AuthController::class, 'enable2FA']);
        Route::post('/2fa/disable', [AuthController::class, 'disable2FA']);
        Route::post('/2fa/verify', [AuthController::class, 'verify2FA']);
        
        // User profile routes
        Route::get('/profile', [UserController::class, 'show']);
        Route::put('/profile', [UserController::class, 'update']);
        Route::post('/profile/complete', [UserController::class, 'completeProfile']);
        Route::post('/profile/photo', [UserController::class, 'uploadPhoto']);
        Route::put('/profile/password', [UserController::class, 'updatePassword']);
        Route::delete('/profile', [UserController::class, 'destroy']);
        
        // Notifications
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::get('/notifications/preferences', [NotificationController::class, 'getPreferences']);
        Route::put('/notifications/preferences', [NotificationController::class, 'updatePreferences']);
        
        // Questionnaire routes (legacy)
        Route::get('/questionnaire', [QuestionnaireController::class, 'show']);
        Route::post('/questionnaire/submit', [QuestionnaireController::class, 'submit']);
        
        // Problem submission routes
        Route::prefix('problems')->group(function () {
            Route::get('/', [ProblemController::class, 'index']);
            Route::get('/{id}', [ProblemController::class, 'show']);
            Route::post('/draft', [ProblemController::class, 'saveDraft']);
            Route::put('/{id}/draft', [ProblemController::class, 'updateDraft']);
            Route::post('/submit', [ProblemController::class, 'submit']);
            Route::post('/{id}/submit', [ProblemController::class, 'submitDraft']);
            Route::delete('/{id}', [ProblemController::class, 'destroy']);
            Route::post('/{id}/attachments', [ProblemController::class, 'addAttachment']);
            Route::delete('/{problemId}/attachments/{attachmentId}', [ProblemController::class, 'removeAttachment']);
        });
        
        // Consultation routes
        Route::get('/consultations', [ConsultationController::class, 'index']);
        Route::post('/consultations', [ConsultationController::class, 'store']);
        Route::get('/consultations/{id}', [ConsultationController::class, 'show']);
        Route::post('/consultations/{id}/shuffle', [ConsultationController::class, 'shuffle']);
        Route::post('/consultations/{id}/cancel', [ConsultationController::class, 'cancel']);
        Route::post('/consultations/{id}/start', [ConsultationController::class, 'start']);
        Route::post('/consultations/{id}/end', [ConsultationController::class, 'end']);
        Route::post('/consultations/{id}/rate', [ConsultationController::class, 'rate']);
        
        // Consultation messages
        Route::get('/consultations/{id}/messages', [ConsultationController::class, 'messages']);
        Route::post('/consultations/{id}/messages', [ConsultationController::class, 'sendMessage']);
        
        // Consultation files
        Route::get('/consultations/{id}/files', [ConsultationController::class, 'files']);
        Route::post('/consultations/{id}/files', [ConsultationController::class, 'uploadFile']);
        Route::get('/consultations/{id}/files/{fileId}/download', [ConsultationController::class, 'downloadFile']);
        
        // Zoom SDK signature
        Route::post('/consultations/{id}/zoom-signature', [ConsultationController::class, 'getZoomSignature']);
        
        // Force end Zoom meeting (for error recovery)
        Route::post('/consultations/{id}/force-end-zoom', [ConsultationController::class, 'forceEndZoomMeeting']);
        
        // Token routes
        Route::get('/tokens/balance', [TokenController::class, 'balance']);
        Route::get('/tokens/transactions', [TokenController::class, 'transactions']);
        Route::post('/tokens/purchase', [TokenController::class, 'purchase']);
        Route::post('/tokens/purchase/confirm', [TokenController::class, 'confirmPurchase']);
        
        // Subscription routes
        Route::get('/subscription', [SubscriptionController::class, 'show']);
        Route::post('/subscription', [SubscriptionController::class, 'subscribe']);
        Route::post('/subscription/cancel', [SubscriptionController::class, 'cancel']);
        
        // GDPR / Privacy routes
        Route::prefix('privacy')->group(function () {
            Route::get('/export', [GdprController::class, 'exportData']);
            Route::get('/download', [GdprController::class, 'downloadData']);
            Route::post('/delete', [GdprController::class, 'requestDeletion']);
            Route::post('/anonymize', [GdprController::class, 'requestAnonymization']);
            Route::get('/policy', [GdprController::class, 'retentionPolicy']);
            Route::get('/settings', [GdprController::class, 'privacySettings']);
        });

        // Consultant-only routes
        Route::middleware('role:consultant')->prefix('consultant')->group(function () {
            Route::get('/dashboard', [ConsultantController::class, 'dashboard']);
            Route::get('/profile', [ConsultantController::class, 'profile']);
            Route::put('/profile', [ConsultantController::class, 'updateProfile']);
            Route::get('/availability', [ConsultantController::class, 'availability']);
            Route::put('/availability', [ConsultantController::class, 'updateAvailability']);
            Route::post('/availability/toggle', [ConsultantController::class, 'toggleAvailability']);
            
            // Work invitations
            Route::get('/invitations', [ConsultantController::class, 'invitations']);
            Route::get('/invitations/{id}', [ConsultantController::class, 'showInvitation']);
            Route::post('/invitations/{id}/accept', [ConsultantController::class, 'acceptInvitation']);
            Route::post('/invitations/{id}/decline', [ConsultantController::class, 'declineInvitation']);
            
            // Legacy requests (kept for compatibility)
            Route::get('/requests', [ConsultantController::class, 'requests']);
            Route::post('/requests/{id}/accept', [ConsultantController::class, 'acceptRequest']);
            Route::post('/requests/{id}/decline', [ConsultantController::class, 'declineRequest']);
            
            // Schedule
            Route::get('/schedule', [ConsultantController::class, 'schedule']);
            Route::get('/calendar', [ConsultantController::class, 'calendar']);
            
            // Surge pricing settings
            Route::get('/surge-settings', [ConsultantController::class, 'surgeSettings']);
            Route::put('/surge-settings', [ConsultantController::class, 'updateSurgeSettings']);
            
            Route::get('/earnings', [ConsultantController::class, 'earnings']);
            Route::get('/consultations', [ConsultantController::class, 'consultationHistory']);
        });
    });
    
    // Stripe webhook (no auth required, verified by signature)
    Route::post('/webhooks/stripe', [TokenController::class, 'handleStripeWebhook']);
});

// Health check
Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()->toISOString()]);
});
