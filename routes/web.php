<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\ProfileController;
use App\Http\Controllers\Web\ConsultationController;
use App\Http\Controllers\Web\ConsultantController;
use App\Http\Controllers\Web\NotificationController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the RouteServiceProvider and assigned to the 
| "web" middleware group. Inertia is used for all frontend rendering.
|
*/

// Public routes
Route::get('/', function () {
    return Inertia::render('Home');
})->name('home');

Route::get('/how-it-works', function () {
    return Inertia::render('HowItWorks');
})->name('how-it-works');

Route::get('/pricing', function () {
    return Inertia::render('Pricing');
})->name('pricing');

Route::get('/using-ai', function () {
    return Inertia::render('UsingAI');
})->name('using-ai');

Route::get('/about', function () {
    return Inertia::render('About');
})->name('about');

Route::get('/terms', function () {
    return Inertia::render('Terms');
})->name('terms');

Route::get('/privacy', function () {
    return Inertia::render('Privacy');
})->name('privacy');

// Guest routes (only accessible when not logged in)
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
    
    Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
});

// Authenticated routes
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // Dashboard - redirect based on role
    Route::get('/dashboard', function () {
        $user = auth()->user();
        
        if ($user->role === 'consultant') {
            return redirect()->route('consultant.dashboard');
        } elseif ($user->role === 'admin') {
            return redirect('/admin');
        }
        
        return Inertia::render('Dashboard');
    })->name('dashboard');
    
    // Profile
    Route::get('/complete-profile', function () {
        return Inertia::render('CompleteProfile');
    })->name('complete-profile');
    Route::post('/complete-profile', [ProfileController::class, 'completeProfile']);
    
    Route::get('/profile', function () {
        return Inertia::render('Profile');
    })->name('profile');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    
    Route::get('/settings', function () {
        return Inertia::render('Settings');
    })->name('settings');
    
    // Tokens
    Route::get('/tokens', function () {
        return Inertia::render('Tokens');
    })->name('tokens');
    
    // Consultations
    Route::get('/consultations', [ConsultationController::class, 'index'])->name('consultations');
    Route::get('/consultations/new', function () {
        return Inertia::render('NewConsultation');
    })->name('consultations.new');
    Route::post('/consultations', [ConsultationController::class, 'store'])->name('consultations.store');
    Route::post('/consultations/{consultationRequest}/accept-time', [ConsultationController::class, 'acceptProposedTime'])->name('consultations.accept-time');
    Route::post('/consultations/{consultationRequest}/counter-time', [ConsultationController::class, 'counterProposeTime'])->name('consultations.counter-time');
    Route::get('/consultations/{consultation}', [ConsultationController::class, 'show'])->name('consultations.show');
    Route::get('/consultations/{consultation}/meeting', [ConsultationController::class, 'meeting'])->name('consultations.meeting');
    Route::post('/consultations/{consultation}/force-end-zoom', [\App\Http\Controllers\Api\V1\ConsultationController::class, 'forceEndZoomMeeting'])->name('consultations.force-end-zoom');
    
    // Alternative route that accepts ConsultationRequest and redirects to Consultation meeting
    Route::get('/consultation-requests/{consultationRequest}/meeting', function ($consultationRequestId) {
        $consultationRequest = \App\Models\ConsultationRequest::findOrFail($consultationRequestId);
        $consultation = \App\Models\Consultation::where('consultation_request_id', $consultationRequest->id)->firstOrFail();
        return redirect()->route('consultations.meeting', ['consultation' => $consultation->id]);
    })->name('consultation-requests.meeting');
    
    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.read-all');
});

// Consultant routes
Route::middleware(['auth', 'role:consultant'])->prefix('consultant')->group(function () {
    Route::get('/', [ConsultantController::class, 'index'])->name('consultant.dashboard');
    
    Route::post('/toggle-availability', [ConsultantController::class, 'toggleAvailability'])
        ->name('consultant.toggle-availability');
    
    Route::get('/invitations', [ConsultantController::class, 'showInvitations'])
        ->name('consultant.invitations');
    
    Route::get('/work', function () {
        return Inertia::render('Consultant/Work');
    })->name('consultant.work');
    
    Route::post('/invitations/{invitation}/accept', [ConsultantController::class, 'acceptInvitation'])
        ->name('consultant.invitations.accept');
    Route::post('/invitations/{invitation}/decline', [ConsultantController::class, 'declineInvitation'])
        ->name('consultant.invitations.decline');
    
    Route::get('/earnings', function () {
        return Inertia::render('Consultant/Earnings');
    })->name('consultant.earnings');
    
    Route::get('/availability', function () {
        return Inertia::render('Consultant/Availability');
    })->name('consultant.availability');
    Route::post('/availability', [ConsultantController::class, 'updateAvailability'])
        ->name('consultant.availability.update');
});
