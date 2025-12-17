<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\ConsultationRequest;
use App\Models\ConsultationMessage;
use App\Models\ConsultationFile;
use App\Models\TokenTransaction;
use App\Models\AuditLog;
use App\Services\ZoomService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ConsultationController extends Controller
{
    /**
     * Get user's consultations.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $consultations = Consultation::forUser($user->id)
            ->with(['consultant.user', 'consultationRequest'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'consultations' => $consultations->items(),
            'pagination' => [
                'current_page' => $consultations->currentPage(),
                'last_page' => $consultations->lastPage(),
                'per_page' => $consultations->perPage(),
                'total' => $consultations->total(),
            ],
        ]);
    }

    /**
     * Create a new consultation (Talk Now).
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'consultation_request_id' => 'required|exists:consultation_requests,id',
        ]);

        $user = $request->user();
        $consultationRequest = ConsultationRequest::findOrFail($request->consultation_request_id);

        // Verify ownership
        if ($consultationRequest->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Check status
        if ($consultationRequest->status !== ConsultationRequest::STATUS_MATCHED) {
            return response()->json([
                'message' => 'Consultation request is not ready. Current status: ' . $consultationRequest->status,
            ], 400);
        }

        // Check tokens
        if ($user->tokens_balance < 10) {
            return response()->json([
                'message' => 'Insufficient tokens',
                'tokens_balance' => $user->tokens_balance,
            ], 400);
        }

        try {
            DB::beginTransaction();

            // Create Zoom meeting (mock for now)
            $zoomMeetingId = 'mtg_' . Str::random(10);
            $zoomJoinUrl = 'https://zoom.us/j/' . $zoomMeetingId;
            $zoomStartUrl = $zoomJoinUrl . '?start=true';

            $consultation = Consultation::create([
                'consultation_request_id' => $consultationRequest->id,
                'user_id' => $user->id,
                'consultant_id' => $consultationRequest->matched_consultant_id,
                'zoom_meeting_id' => $zoomMeetingId,
                'zoom_join_url' => $zoomJoinUrl,
                'zoom_start_url' => $zoomStartUrl,
                'status' => Consultation::STATUS_SCHEDULED,
                'token_rate_per_minute' => 1.00,
            ]);

            $consultationRequest->update(['status' => ConsultationRequest::STATUS_IN_PROGRESS]);

            AuditLog::log(
                'consultation_created',
                $user->id,
                Consultation::class,
                $consultation->id
            );

            DB::commit();

            return response()->json([
                'message' => 'Consultation created successfully',
                'consultation' => [
                    'id' => $consultation->id,
                    'zoom_join_url' => $consultation->zoom_join_url,
                    'status' => $consultation->status,
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to create consultation'], 500);
        }
    }

    /**
     * Get consultation details.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $consultation = Consultation::with(['consultant.user', 'consultationRequest', 'messages', 'files'])
            ->findOrFail($id);

        // Verify access - user must be owner OR the assigned consultant
        if ($consultation->user_id !== $user->id &&
            (!$user->consultantProfile || $consultation->consultant_id !== $user->consultantProfile->id)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'consultation' => $this->formatConsultationResponse($consultation),
        ]);
    }

    /**
     * Shuffle consultant.
     */
    public function shuffle(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $consultation = Consultation::findOrFail($id);

        if ($consultation->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $consultationRequest = $consultation->consultationRequest;

        if (!$consultationRequest->canShuffle()) {
            return response()->json([
                'message' => 'Maximum shuffles reached or consultation is no longer eligible for shuffle',
                'remaining_shuffles' => $consultationRequest->remaining_shuffles,
            ], 400);
        }

        // Check if within 5-minute window
        if ($consultation->started_at && $consultation->started_at->diffInMinutes(now()) > 5) {
            return response()->json([
                'message' => 'Shuffle window has expired (5 minutes from start)',
            ], 400);
        }

        try {
            DB::beginTransaction();

            // Exclude current consultant
            $consultationRequest->excludeConsultant($consultation->consultant_id);
            $consultationRequest->increment('shuffle_count');
            $consultationRequest->update(['status' => ConsultationRequest::STATUS_MATCHING]);

            // Cancel current consultation
            $consultation->update(['status' => Consultation::STATUS_CANCELLED]);

            AuditLog::log(
                'consultation_shuffled',
                $user->id,
                Consultation::class,
                $consultation->id,
                null,
                ['shuffle_count' => $consultationRequest->shuffle_count]
            );

            DB::commit();

            // In production, dispatch job to find new consultant
            // MatchConsultantJob::dispatch($consultationRequest);

            return response()->json([
                'message' => 'Shuffling to find a new consultant',
                'remaining_shuffles' => $consultationRequest->remaining_shuffles,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Shuffle failed'], 500);
        }
    }

    /**
     * Cancel consultation.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $consultation = Consultation::findOrFail($id);

        if ($consultation->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!in_array($consultation->status, [Consultation::STATUS_SCHEDULED])) {
            return response()->json([
                'message' => 'Cannot cancel consultation with status: ' . $consultation->status,
            ], 400);
        }

        $consultation->update(['status' => Consultation::STATUS_CANCELLED]);
        $consultation->consultationRequest->update(['status' => ConsultationRequest::STATUS_CANCELLED]);

        AuditLog::log('consultation_cancelled', $user->id, Consultation::class, $consultation->id);

        return response()->json(['message' => 'Consultation cancelled']);
    }

    /**
     * Start consultation.
     */
    public function start(Request $request, int $id): JsonResponse
    {
        $consultation = Consultation::findOrFail($id);

        if (!$consultation->canStart()) {
            return response()->json([
                'message' => 'Consultation cannot be started',
            ], 400);
        }

        $consultation->update([
            'status' => Consultation::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ]);

        AuditLog::log('consultation_started', $request->user()->id, Consultation::class, $consultation->id);

        return response()->json([
            'message' => 'Consultation started',
            'started_at' => $consultation->started_at,
        ]);
    }

    /**
     * End consultation.
     */
    public function end(Request $request, int $id): JsonResponse
    {
        $consultation = Consultation::findOrFail($id);

        if (!$consultation->canEnd()) {
            return response()->json([
                'message' => 'Consultation cannot be ended',
            ], 400);
        }

        // Defensive check: ensure started_at exists before calculating duration
        if (!$consultation->started_at) {
            return response()->json([
                'message' => 'Consultation has no start time recorded',
            ], 400);
        }

        try {
            DB::beginTransaction();

            $endedAt = now();
            $durationMinutes = $consultation->started_at->diffInMinutes($endedAt);
            $tokensToCharge = (int) ceil($durationMinutes * $consultation->token_rate_per_minute);

            $user = $consultation->user;
            $oldBalance = $user->tokens_balance;
            $newBalance = max(0, $oldBalance - $tokensToCharge);

            $user->update(['tokens_balance' => $newBalance]);

            TokenTransaction::create([
                'user_id' => $user->id,
                'type' => TokenTransaction::TYPE_DEDUCTION,
                'amount' => -$tokensToCharge,
                'balance_after' => $newBalance,
                'description' => "Consultation #{$consultation->id} - {$durationMinutes} minutes",
                'consultation_id' => $consultation->id,
            ]);

            $consultation->update([
                'status' => Consultation::STATUS_COMPLETED,
                'ended_at' => $endedAt,
                'duration_minutes' => $durationMinutes,
                'tokens_charged' => $tokensToCharge,
            ]);

            $consultation->consultationRequest->update(['status' => ConsultationRequest::STATUS_COMPLETED]);
            
            // CRITICAL: End the meeting on Zoom's servers to clear server-side state
            if ($consultation->zoom_meeting_id) {
                Log::info('Attempting to end Zoom meeting on their servers', [
                    'consultation_id' => $consultation->id,
                    'zoom_meeting_id' => $consultation->zoom_meeting_id,
                ]);
                
                $zoomService = app(\App\Services\ZoomService::class);
                $ended = $zoomService->endMeeting($consultation->zoom_meeting_id);
                
                Log::info('Zoom meeting end result', [
                    'consultation_id' => $consultation->id,
                    'success' => $ended,
                ]);
            }

            AuditLog::log(
                'consultation_ended',
                $request->user()->id,
                Consultation::class,
                $consultation->id,
                null,
                [
                    'duration_minutes' => $durationMinutes,
                    'tokens_charged' => $tokensToCharge,
                ]
            );

            DB::commit();

            return response()->json([
                'message' => 'Consultation ended',
                'duration_minutes' => $durationMinutes,
                'tokens_charged' => $tokensToCharge,
                'new_balance' => $newBalance,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to end consultation'], 500);
        }
    }

    /**
     * Rate consultation.
     */
    public function rate(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'feedback' => 'nullable|string|max:1000',
        ]);

        $consultation = Consultation::findOrFail($id);

        if ($consultation->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($consultation->status !== Consultation::STATUS_COMPLETED) {
            return response()->json(['message' => 'Can only rate completed consultations'], 400);
        }

        $consultation->update([
            'user_rating' => $request->rating,
            'user_feedback' => $request->feedback,
        ]);

        return response()->json(['message' => 'Rating submitted']);
    }

    /**
     * Get consultation messages.
     */
    public function messages(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $consultation = Consultation::findOrFail($id);

        // Verify access - user must be owner OR the assigned consultant
        if ($consultation->user_id !== $user->id &&
            (!$user->consultantProfile || $consultation->consultant_id !== $user->consultantProfile->id)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $messages = $consultation->messages()
            ->with('user')
            ->orderBy('created_at')
            ->get();

        return response()->json(['messages' => $messages]);
    }

    /**
     * Send message.
     */
    public function sendMessage(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:5000',
            'type' => 'sometimes|in:text,code_snippet',
            'metadata' => 'sometimes|array',
        ]);

        $user = $request->user();
        $consultation = Consultation::findOrFail($id);

        // Verify access - user must be owner OR the assigned consultant
        if ($consultation->user_id !== $user->id &&
            (!$user->consultantProfile || $consultation->consultant_id !== $user->consultantProfile->id)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $message = ConsultationMessage::create([
            'consultation_id' => $consultation->id,
            'user_id' => $request->user()->id,
            'message' => $request->message,
            'type' => $request->type ?? ConsultationMessage::TYPE_TEXT,
            'metadata' => $request->metadata,
        ]);

        // In production, broadcast via WebSocket
        // event(new MessageSent($message));

        return response()->json([
            'message' => $message->load('user'),
        ], 201);
    }

    /**
     * Get consultation files.
     */
    public function files(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $consultation = Consultation::findOrFail($id);

        // Verify access - user must be owner OR the assigned consultant
        if ($consultation->user_id !== $user->id &&
            (!$user->consultantProfile || $consultation->consultant_id !== $user->consultantProfile->id)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $files = $consultation->files()
            ->safe()
            ->with('uploader')
            ->orderBy('created_at')
            ->get();

        return response()->json(['files' => $files]);
    }

    /**
     * Upload file.
     */
    public function uploadFile(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:10240', // 10MB
        ]);

        $user = $request->user();
        $consultation = Consultation::findOrFail($id);

        // Verify access - user must be owner OR the assigned consultant
        if ($consultation->user_id !== $user->id &&
            (!$user->consultantProfile || $consultation->consultant_id !== $user->consultantProfile->id)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $file = $request->file('file');

        if (!ConsultationFile::isAllowedMimeType($file->getMimeType())) {
            return response()->json([
                'message' => 'File type not allowed',
            ], 400);
        }

        $storedName = Str::uuid() . 'V1' . $file->getClientOriginalExtension();
        $path = $file->storeAs("consultations/{$consultation->id}", $storedName, 'private');

        $consultationFile = ConsultationFile::create([
            'consultation_id' => $consultation->id,
            'uploaded_by' => $request->user()->id,
            'original_name' => $file->getClientOriginalName(),
            'stored_name' => $storedName,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'path' => $path,
            'is_scanned' => true, // In production, queue for malware scanning
            'is_safe' => true,
        ]);

        return response()->json([
            'file' => $consultationFile->load('uploader'),
        ], 201);
    }

    /**
     * Download a consultation file.
     */
    public function downloadFile(Request $request, int $id, int $fileId): mixed
    {
        $consultation = Consultation::findOrFail($id);
        $user = $request->user();

        // Verify access
        if ($consultation->user_id !== $user->id &&
            (!$user->consultantProfile || $consultation->consultant_id !== $user->consultantProfile->id)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $file = ConsultationFile::where('consultation_id', $consultation->id)
            ->where('id', $fileId)
            ->safe()
            ->firstOrFail();

        if (!Storage::disk('private')->exists($file->path)) {
            return response()->json(['message' => 'File not found'], 404);
        }

        return Storage::disk('private')->download($file->path, $file->original_name);
    }

    /**
     * Get Zoom SDK signature for client-side SDK.
     */
    public function getZoomSignature(Request $request, int $id): JsonResponse
    {
        $consultation = Consultation::findOrFail($id);
        $user = $request->user();

        // Verify access
        if ($consultation->user_id !== $user->id &&
            (!$user->consultantProfile || $consultation->consultant_id !== $user->consultantProfile->id)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$consultation->zoom_meeting_id) {
            return response()->json(['message' => 'No Zoom meeting associated with this consultation'], 400);
        }

        // Determine role: 0 = participant (user), 1 = host (consultant)
        $isHost = $user->consultantProfile && $consultation->consultant_id === $user->consultantProfile->id;
        $role = $isHost ? 1 : 0;

        try {
            $zoomService = app(ZoomService::class);
            $signature = $zoomService->generateSignature($consultation->zoom_meeting_id, $role);

            return response()->json([
                'signature' => $signature,
                'meeting_number' => $consultation->zoom_meeting_id,
                'role' => $role,
                'sdk_key' => config('services.zoom.sdk_key'),
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to generate Zoom signature'], 500);
        }
    }

    /**
     * Format consultation response.
     */
    private function formatConsultationResponse(Consultation $consultation): array
    {
        return [
            'id' => $consultation->id,
            'status' => $consultation->status,
            'zoom_meeting_id' => $consultation->zoom_meeting_id,
            'zoom_join_url' => $consultation->zoom_join_url,
            'started_at' => $consultation->started_at,
            'ended_at' => $consultation->ended_at,
            'duration_minutes' => $consultation->duration_minutes,
            'tokens_charged' => $consultation->tokens_charged,
            'user_rating' => $consultation->user_rating,
            'consultant' => $consultation->consultant ? [
                'id' => $consultation->consultant->id,
                'name' => $consultation->consultant->user->full_name,
                'specializations' => $consultation->consultant->specializations,
            ] : null,
            'request' => $consultation->consultationRequest ? [
                'problem_description' => $consultation->consultationRequest->problem_description,
                'tech_stack' => $consultation->consultationRequest->tech_stack,
            ] : null,
            'messages_count' => $consultation->messages->count(),
            'files_count' => $consultation->files->count(),
            'created_at' => $consultation->created_at,
        ];
    }

    /**
     * Force end a Zoom meeting (for error recovery).
     * This is used when a join attempt fails but Zoom's servers think the user is still in the meeting.
     */
    public function forceEndZoomMeeting(Request $request, int $id): JsonResponse
    {
        $consultation = Consultation::findOrFail($id);

        if (!$consultation->zoom_meeting_id) {
            return response()->json([
                'message' => 'No Zoom meeting associated with this consultation',
            ], 400);
        }

        Log::info('Force ending Zoom meeting for error recovery', [
            'consultation_id' => $consultation->id,
            'zoom_meeting_id' => $consultation->zoom_meeting_id,
            'user_id' => $request->user()->id,
        ]);

        $zoomService = app(\App\Services\ZoomService::class);
        $ended = $zoomService->endMeeting($consultation->zoom_meeting_id);

        if ($ended) {
            Log::info('Successfully force-ended Zoom meeting', [
                'consultation_id' => $consultation->id,
                'zoom_meeting_id' => $consultation->zoom_meeting_id,
            ]);

            return response()->json([
                'message' => 'Zoom meeting ended on servers',
                'success' => true,
            ]);
        }

        Log::warning('Failed to force-end Zoom meeting', [
            'consultation_id' => $consultation->id,
            'zoom_meeting_id' => $consultation->zoom_meeting_id,
        ]);

        return response()->json([
            'message' => 'Failed to end Zoom meeting (may already be ended)',
            'success' => false,
        ]);
    }
}

