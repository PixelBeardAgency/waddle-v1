<?php

namespace App\Services;

use App\Models\Consultation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ZoomService
{
    private string $accountId;
    private string $clientId;
    private string $clientSecret;
    private string $baseUrl = 'https://api.zoom.us/v2';

    public function __construct()
    {
        $this->accountId = config('services.zoom.account_id');
        $this->clientId = config('services.zoom.client_id');
        $this->clientSecret = config('services.zoom.client_secret');
    }

    /**
     * Get OAuth access token using Server-to-Server OAuth.
     */
    private function getAccessToken(): string
    {
        $cacheKey = 'zoom_access_token';
        
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
            ->asForm()
            ->post('https://zoom.us/oauth/token', [
                'grant_type' => 'account_credentials',
                'account_id' => $this->accountId,
            ]);

        if (!$response->successful()) {
            Log::error('Failed to get Zoom access token', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('Failed to authenticate with Zoom');
        }

        $data = $response->json();
        $token = $data['access_token'];
        $expiresIn = $data['expires_in'] - 60; // Buffer of 60 seconds

        Cache::put($cacheKey, $token, $expiresIn);

        return $token;
    }

    /**
     * Create a Zoom meeting for a consultation.
     */
    public function createMeeting(Consultation $consultation): array
    {
        $maxRetries = 3;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                $token = $this->getAccessToken();
                
                $response = Http::withToken($token)
                    ->timeout(30)
                    ->post("{$this->baseUrl}/users/me/meetings", [
                        'topic' => "Waddle Consultation #{$consultation->id}",
                        'type' => 2, // Scheduled meeting (more reliable than instant)
                        'start_time' => now()->addMinutes(5)->toIso8601String(),
                        'duration' => 60, // Default 60 minutes
                        'timezone' => 'UTC',
                        'settings' => [
                            'host_video' => true,
                            'participant_video' => true,
                            'join_before_host' => true, // Allow participants to join before host
                            'mute_upon_entry' => false,
                            'watermark' => false,
                            'use_pmi' => false,
                            'approval_type' => 0,
                            'audio' => 'both',
                            'auto_recording' => 'none', // Disable recording for testing
                            'waiting_room' => false, // Disable waiting room for easier testing
                            'allow_multiple_devices' => true, // Allow multiple devices
                            'meeting_authentication' => false, // Disable authentication requirement
                        ],
                        'password' => '', // Explicitly set no password
                    ]);

                if ($response->successful()) {
                    $data = $response->json();
                    
                    Log::info('Zoom meeting created', [
                        'consultation_id' => $consultation->id,
                        'meeting_id' => $data['id'],
                    ]);

                    return [
                        'meeting_id' => (string) $data['id'],
                        'join_url' => $data['join_url'],
                        'start_url' => $data['start_url'],
                        'password' => $data['password'] ?? null,
                    ];
                }

                Log::warning('Zoom meeting creation failed', [
                    'attempt' => $attempt + 1,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

            } catch (\Exception $e) {
                Log::error('Zoom API error', [
                    'attempt' => $attempt + 1,
                    'error' => $e->getMessage(),
                ]);
            }

            $attempt++;
            if ($attempt < $maxRetries) {
                sleep(pow(2, $attempt)); // Exponential backoff
            }
        }

        throw new \Exception('Failed to create Zoom meeting after ' . $maxRetries . ' attempts');
    }

    /**
     * End a Zoom meeting.
     */
    public function endMeeting(string $meetingId): bool
    {
        try {
            $token = $this->getAccessToken();
            
            $response = Http::withToken($token)
                ->timeout(30)
                ->put("{$this->baseUrl}/meetings/{$meetingId}/status", [
                    'action' => 'end',
                ]);

            if ($response->successful() || $response->status() === 204) {
                Log::info('Zoom meeting ended', ['meeting_id' => $meetingId]);
                return true;
            }

            Log::warning('Failed to end Zoom meeting', [
                'meeting_id' => $meetingId,
                'status' => $response->status(),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('Error ending Zoom meeting', [
                'meeting_id' => $meetingId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get meeting details.
     */
    public function getMeeting(string $meetingId): ?array
    {
        try {
            $token = $this->getAccessToken();
            
            $response = Http::withToken($token)
                ->timeout(30)
                ->get("{$this->baseUrl}/meetings/{$meetingId}");

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Error getting Zoom meeting', [
                'meeting_id' => $meetingId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get meeting recordings.
     */
    public function getRecordings(string $meetingId): ?array
    {
        try {
            $token = $this->getAccessToken();
            
            $response = Http::withToken($token)
                ->timeout(30)
                ->get("{$this->baseUrl}/meetings/{$meetingId}/recordings");

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Error getting Zoom recordings', [
                'meeting_id' => $meetingId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Generate SDK signature for client-side Zoom SDK.
     */
    public function generateSignature(string $meetingNumber, int $role): string
    {
        $sdkKey = config('services.zoom.sdk_key');
        $sdkSecret = config('services.zoom.sdk_secret');
        $clientId = config('services.zoom.client_id');
        
        $iat = time();
        $exp = $iat + 60 * 60 * 2; // 2 hours

        // For Meeting SDK v5+, use format: appKey:clientId
        $payload = [
            'appKey' => $sdkKey, // Still using SDK key as the app key
            'mn' => (string) $meetingNumber,
            'role' => (int) $role,
            'iat' => $iat,
            'exp' => $exp,
            'tokenExp' => $exp,
        ];

        // Log for debugging
        Log::info('Generating Zoom signature', [
            'meeting_number' => $meetingNumber,
            'role' => $role,
            'sdk_key' => substr($sdkKey, 0, 8) . '...',
            'payload' => $payload,
        ]);

        $header = json_encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $payload = json_encode($payload);

        $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));

        $signature = hash_hmac('sha256', $base64Header . '.' . $base64Payload, $sdkSecret, true);
        $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

        $jwt = $base64Header . '.' . $base64Payload . '.' . $base64Signature;
        
        Log::info('Generated signature', ['jwt_preview' => substr($jwt, 0, 50) . '...']);

        return $jwt;
    }
}

