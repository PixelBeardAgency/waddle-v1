<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\ConsultationRequest;
use App\Services\ZoomService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class ConsultationController extends Controller
{
    /**
     * Display a listing of the user's consultation requests and active consultations.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        // Get consultation requests
        $requests = ConsultationRequest::where('user_id', $user->id)
            ->with(['matchedConsultant.user'])
            ->latest()
            ->get();
        
        // Log for debugging
        \Log::info('Consultations index', [
            'user_id' => $user->id,
            'requests_count' => $requests->count(),
            'requests' => $requests->map(fn($r) => [
                'id' => $r->id,
                'status' => $r->status,
                'problem_description' => substr($r->problem_description, 0, 50)
            ])
        ]);
            
        // Get active/completed consultations
        $consultations = Consultation::where('user_id', $user->id)
            ->with(['consultant.user'])
            ->latest()
            ->get();

        return Inertia::render('Consultations', [
            'requests' => $requests,
            'consultations' => $consultations,
        ]);
    }

    /**
     * Store a new consultation request.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'problem_description' => ['required', 'string', 'min:20', 'max:5000'],
            'tech_stack' => ['required', 'array', 'min:1'],
            'tech_stack.*' => ['string'],
            'error_logs' => ['nullable', 'string', 'max:10000'],
            'questionnaire_responses' => ['nullable', 'array'],
        ]);

        $user = $request->user();

        // Check if user has tokens
        if ($user->tokens_balance < 1) {
            return back()->withErrors([
                'tokens' => 'You need tokens to request a consultation. Please purchase tokens first.',
            ]);
        }

        // Create the consultation request
        $consultationRequest = ConsultationRequest::create([
            'user_id' => $user->id,
            'problem_description' => $validated['problem_description'],
            'tech_stack' => $validated['tech_stack'],
            'error_logs' => $validated['error_logs'] ?? null,
            'questionnaire_responses' => $validated['questionnaire_responses'] ?? [],
            'status' => ConsultationRequest::STATUS_PENDING,
            'shuffle_count' => 0,
            'excluded_consultants' => [],
        ]);

        return redirect('/consultations')
            ->with('success', 'Consultation request submitted! We\'re finding the best consultant for you.');
    }

    /**
     * Accept the proposed time from consultant.
     */
    public function acceptProposedTime(Request $request, ConsultationRequest $consultationRequest)
    {
        $user = $request->user();

        // Verify ownership
        if ($consultationRequest->user_id !== $user->id) {
            abort(403, 'You are not authorized to accept this time.');
        }

        // Verify status
        if ($consultationRequest->status !== ConsultationRequest::STATUS_TIME_PROPOSED) {
            return back()->withErrors(['error' => 'This consultation is not awaiting time acceptance.']);
        }

        // Update consultation request
        $consultationRequest->update([
            'status' => ConsultationRequest::STATUS_SCHEDULED,
            'agreed_time' => $consultationRequest->proposed_time,
            'user_confirmed' => true,
            'consultant_confirmed' => true,
        ]);

        // Create Consultation record (for tracking the actual meeting)
        Consultation::create([
            'consultation_request_id' => $consultationRequest->id,
            'user_id' => $consultationRequest->user_id,
            'consultant_id' => $consultationRequest->matched_consultant_id,
            'scheduled_at' => $consultationRequest->agreed_time,
            'duration_minutes' => 60, // Default duration
            'status' => Consultation::STATUS_SCHEDULED,
        ]);

        // TODO: Send notification to consultant

        return back()->with('success', 'Time accepted! Your consultation has been scheduled.');
    }

    /**
     * Counter-propose a different time.
     */
    public function counterProposeTime(Request $request, ConsultationRequest $consultationRequest)
    {
        $user = $request->user();

        // Verify ownership
        if ($consultationRequest->user_id !== $user->id) {
            abort(403, 'You are not authorized to counter-propose for this consultation.');
        }

        // Verify status
        if (!in_array($consultationRequest->status, [
            ConsultationRequest::STATUS_TIME_PROPOSED,
            ConsultationRequest::STATUS_TIME_COUNTER_PROPOSED,
        ])) {
            return back()->withErrors(['error' => 'This consultation is not in a state to counter-propose time.']);
        }

        $validated = $request->validate([
            'counter_proposed_time' => ['required', 'date', 'after:now'],
            'counter_proposal_reason' => ['nullable', 'string', 'max:500'],
        ]);

        // Update consultation request
        $consultationRequest->update([
            'status' => ConsultationRequest::STATUS_TIME_COUNTER_PROPOSED,
            'counter_proposed_time' => $validated['counter_proposed_time'],
            'counter_proposal_reason' => $validated['counter_proposal_reason'] ?? null,
            'proposal_rounds' => $consultationRequest->proposal_rounds + 1,
        ]);

        // TODO: Send notification to consultant

        return back()->with('success', 'Your counter-proposal has been sent to the consultant.');
    }

    /**
     * Display a specific consultation.
     */
    public function show(Consultation $consultation)
    {
        // Authorization check - ensure user owns this consultation
        if ($consultation->user_id !== request()->user()->id) {
            abort(403);
        }

        $consultation->load(['consultant.user', 'messages', 'files']);

        return Inertia::render('Consultation/Show', [
            'consultation' => $consultation,
        ]);
    }

    /**
     * Display the Zoom meeting page for a consultation.
     */
    public function meeting(Consultation $consultation, ZoomService $zoomService)
    {
        $user = request()->user();
        
        // Authorization check - user or consultant can access
        $isUser = $consultation->user_id === $user->id;
        $isConsultant = $consultation->consultant_id === optional($user->consultant)->id;
        
        if (!$isUser && !$isConsultant) {
            abort(403, 'You are not authorized to join this meeting.');
        }

        // Check consultation has a meeting
        if (!$consultation->zoom_meeting_id) {
            return Inertia::render('Consultation/MeetingUnavailable', [
                'reason' => 'not_created',
                'message' => 'No meeting room has been created yet.',
                'consultation_id' => $consultation->id,
            ]);
        }

        // Check consultation status allows joining
        $allowedStatuses = [
            Consultation::STATUS_SCHEDULED,
            Consultation::STATUS_IN_PROGRESS,
        ];
        
        if (!in_array($consultation->status, $allowedStatuses)) {
            $reason = $consultation->status === Consultation::STATUS_COMPLETED 
                ? 'completed' 
                : 'not_active';
            
            return Inertia::render('Consultation/MeetingUnavailable', [
                'reason' => $reason,
                'message' => $consultation->status === Consultation::STATUS_COMPLETED
                    ? 'This consultation has ended.'
                    : 'This consultation is not currently active.',
                'consultation_id' => $consultation->id,
            ]);
        }

        // Verify the meeting still exists in Zoom
        $meetingDetails = $zoomService->getMeeting($consultation->zoom_meeting_id);
        if (!$meetingDetails) {
            Log::warning('Zoom meeting not found', [
                'consultation_id' => $consultation->id,
                'meeting_id' => $consultation->zoom_meeting_id,
            ]);
            
            return Inertia::render('Consultation/MeetingUnavailable', [
                'reason' => 'expired',
                'message' => 'This meeting room has expired or is no longer available.',
                'consultation_id' => $consultation->id,
            ]);
        }

        $consultation->load(['user', 'consultant.user']);

        // Role: 0 = participant (user), 1 = host (consultant)
        $role = $isConsultant ? 1 : 0;

        // Generate Zoom SDK signature
        $signature = $zoomService->generateSignature($consultation->zoom_meeting_id, $role);

        Log::info('Meeting page accessed', [
            'consultation_id' => $consultation->id,
            'meeting_id' => $consultation->zoom_meeting_id,
            'password' => $consultation->zoom_password,
            'role' => $role,
        ]);

        return Inertia::render('Consultation/Meeting', [
            'consultation' => [
                'id' => $consultation->id,
                'zoom_meeting_id' => $consultation->zoom_meeting_id,
                'zoom_join_url' => $consultation->zoom_join_url,
                'status' => $consultation->status,
                'user' => [
                    'id' => $consultation->user->id,
                    'full_name' => $consultation->user->full_name,
                ],
                'consultant' => [
                    'id' => $consultation->consultant->id,
                    'user' => [
                        'full_name' => $consultation->consultant->user->full_name,
                    ],
                ],
            ],
            'signature' => $signature,
            'sdkKey' => config('services.zoom.sdk_key'), // Pass SDK Key to frontend
            'meetingNumber' => $consultation->zoom_meeting_id,
            'password' => $consultation->zoom_password ?? '', // Pass meeting password
            'userName' => $user->full_name,
            'userEmail' => $user->email,
            'role' => $role,
        ]);
    }
}
