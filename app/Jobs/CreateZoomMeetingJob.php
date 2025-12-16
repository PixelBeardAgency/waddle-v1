<?php

namespace App\Jobs;

use App\Models\ConsultationRequest;
use App\Services\ZoomService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CreateZoomMeetingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(ZoomService $zoomService): void
    {
        // Find consultation requests that are scheduled and need Zoom rooms created
        // (agreed_time is within 5 minutes from now)
        $consultationRequests = ConsultationRequest::where('status', ConsultationRequest::STATUS_SCHEDULED)
            ->whereNotNull('agreed_time')
            ->whereNull('zoom_meeting_id')
            ->where('agreed_time', '<=', Carbon::now()->addMinutes(5))
            ->where('agreed_time', '>', Carbon::now())
            ->with(['user', 'matchedConsultant.user'])
            ->get();

        foreach ($consultationRequests as $request) {
            try {
                Log::info('Creating Zoom meeting for consultation request', [
                    'consultation_request_id' => $request->id,
                    'agreed_time' => $request->agreed_time,
                ]);

                // Create a temporary Consultation object for the ZoomService
                // (ZoomService expects a Consultation model)
                $consultation = new \App\Models\Consultation([
                    'id' => $request->id,
                    'user_id' => $request->user_id,
                    'consultant_id' => $request->matched_consultant_id,
                ]);

                // Create Zoom meeting
                $meeting = $zoomService->createMeeting($consultation);

                // Update consultation request with Zoom details
                $request->update([
                    'zoom_meeting_id' => $meeting['meeting_id'],
                    'zoom_join_url' => $meeting['join_url'],
                    'zoom_password' => $meeting['password'] ?? null,
                    'zoom_created_at' => now(),
                    'status' => ConsultationRequest::STATUS_READY,
                ]);

                // Create or update the Consultation record
                $consultationRecord = \App\Models\Consultation::updateOrCreate(
                    ['consultation_request_id' => $request->id],
                    [
                        'user_id' => $request->user_id,
                        'consultant_id' => $request->matched_consultant_id,
                        'scheduled_at' => $request->agreed_time,
                        'duration_minutes' => 60,
                        'status' => \App\Models\Consultation::STATUS_SCHEDULED,
                        'zoom_meeting_id' => $meeting['meeting_id'],
                        'zoom_join_url' => $meeting['join_url'],
                        'zoom_password' => $meeting['password'] ?? null,
                    ]
                );

                Log::info('Zoom meeting created successfully', [
                    'consultation_request_id' => $request->id,
                    'consultation_id' => $consultationRecord->id,
                    'zoom_meeting_id' => $meeting['meeting_id'],
                ]);

                // TODO: Send notifications to both user and consultant
                // Notification::send($request->user, new ZoomMeetingReadyNotification($request));
                // Notification::send($request->matchedConsultant->user, new ZoomMeetingReadyNotification($request));

            } catch (\Exception $e) {
                Log::error('Failed to create Zoom meeting', [
                    'consultation_request_id' => $request->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        if ($consultationRequests->count() > 0) {
            Log::info('Processed Zoom meeting creation job', [
                'processed_count' => $consultationRequests->count(),
            ]);
        }
    }
}
