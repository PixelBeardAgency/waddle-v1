import React, { useEffect, useRef, useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { Layout } from '@/Components/layout';
import { Button } from '@/Components/ui/Button';
import { Alert, AlertDescription } from '@/Components/ui/Alert';
import { Loader2, AlertCircle, Phone, PhoneOff } from 'lucide-react';
import type { PageProps } from '@/types';
import ZoomMtgEmbedded from '@zoom/meetingsdk/embedded';

interface MeetingPageProps extends PageProps {
  consultation: {
    id: number;
    zoom_meeting_id: string;
    zoom_join_url: string;
    status: string;
    user: {
      id: number;
      full_name: string;
    };
    consultant: {
      id: number;
      user: {
        full_name: string;
      };
    };
  };
  signature: string;
  sdkKey: string;
  meetingNumber: string;
  password: string;
  userName: string;
  userEmail: string;
  role: number; // 0 = participant, 1 = host
}

export default function Meeting() {
  const { consultation, signature, sdkKey, meetingNumber, password, userName, userEmail, role } = 
    usePage<MeetingPageProps>().props;
  
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [isJoined, setIsJoined] = useState(false);
  const meetingContainerRef = useRef<HTMLDivElement>(null);
  const zoomClientRef = useRef<any>(null);
  const hasJoinedRef = useRef(false);

  useEffect(() => {
    console.log('=== COMPONENT MOUNTED ===');
    console.log('Meeting Number:', meetingNumber);
    console.log('User:', userName);
    console.log('Role:', role === 1 ? 'Host/Consultant' : 'Participant/User');
    
    // Check if we came from a previous meeting (page reload scenario)
    const hadPreviousMeeting = sessionStorage.getItem('waddle_in_meeting');
    if (hadPreviousMeeting) {
      console.log('⚠️ Detected previous meeting session, clearing...');
      sessionStorage.removeItem('waddle_in_meeting');
    }

    return () => {
      console.log('=== COMPONENT UNMOUNTING ===');
      // Mark that we had a meeting session
      if (hasJoinedRef.current) {
        sessionStorage.setItem('waddle_left_meeting', 'true');
      }
      
      // Aggressive cleanup on unmount
      if (zoomClientRef.current) {
        try {
          console.log('Leaving meeting on unmount...');
          zoomClientRef.current.leaveMeeting().catch(() => {
            console.log('No active meeting to leave');
          });
          
          // Give it time to leave, then destroy
          setTimeout(() => {
            try {
              zoomClientRef.current?.destroy?.();
              console.log('Zoom client destroyed');
            } catch (e) {
              console.log('Error destroying client:', e);
            }
            zoomClientRef.current = null;
          }, 500);
        } catch (e) {
          console.log('Cleanup error:', e);
        }
      }
      hasJoinedRef.current = false;
    };
  }, []);

  const joinMeeting = async () => {
    console.log('=== JOIN MEETING CALLED ===');
    console.log('hasJoinedRef:', hasJoinedRef.current);
    console.log('isJoined:', isJoined);
    console.log('Current zoomClientRef:', zoomClientRef.current);

    // Check if we just left a meeting - if so, FORCE PAGE RELOAD to reset Zoom SDK
    const justLeftMeeting = sessionStorage.getItem('waddle_left_meeting');
    if (justLeftMeeting) {
      console.log('🔄 Detected previous meeting exit - RELOADING PAGE to reset Zoom SDK...');
      sessionStorage.removeItem('waddle_left_meeting');
      // Add cache buster to force fresh page load
      window.location.href = window.location.href.split('?')[0] + '?v=' + Date.now();
      return;
    }
    
    // Track how many times we've tried to join
    const joinAttempts = parseInt(sessionStorage.getItem('waddle_join_attempts') || '0');
    if (joinAttempts > 0) {
      console.log(`⚠️ This is join attempt #${joinAttempts + 1} - clearing and reloading...`);
      sessionStorage.removeItem('waddle_join_attempts');
      sessionStorage.setItem('waddle_left_meeting', 'true');
      window.location.href = window.location.href.split('?')[0] + '?fresh=' + Date.now();
      return;
    }
    sessionStorage.setItem('waddle_join_attempts', String(joinAttempts + 1));

    // Prevent duplicate joins
    if (hasJoinedRef.current || isJoined) {
      console.log('⚠️ Already joined or joining, skipping...');
      return;
    }

    // Check if mediaDevices is available
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      setError('Your browser does not support video/audio. Please use a modern browser (Chrome, Firefox, Safari, or Edge) and ensure you are accessing the site via HTTPS or localhost.');
      return;
    }

    // Mark as joined immediately to prevent duplicates
    hasJoinedRef.current = true;
    setIsLoading(true);
    setError(null);
    
    // Mark that we're in a meeting
    sessionStorage.setItem('waddle_in_meeting', 'true');

    // NUCLEAR CLEANUP: Destroy ALL possible Zoom state
    console.log('🧹 Starting nuclear cleanup...');
    
    // 1. Try to leave and destroy current client
    if (zoomClientRef.current) {
      console.log('Cleaning up existing client...');
      try {
        // Check if we're in a meeting first
        const getCurrentUser = zoomClientRef.current.getCurrentUser?.();
        console.log('Current user in client:', getCurrentUser);
        
        if (getCurrentUser) {
          console.log('User is in meeting, leaving...');
          await zoomClientRef.current.leaveMeeting();
          await new Promise(resolve => setTimeout(resolve, 500));
        }
        
        console.log('Destroying client...');
        zoomClientRef.current?.destroy?.();
        await new Promise(resolve => setTimeout(resolve, 500));
      } catch (e) {
        console.log('Cleanup error (ignoring):', e);
      }
      zoomClientRef.current = null;
    }
    
    // 2. Clear any browser storage that Zoom might use
    console.log('Clearing browser storage...');
    try {
      // Clear session storage
      const keysToRemove = [];
      for (let i = 0; i < sessionStorage.length; i++) {
        const key = sessionStorage.key(i);
        if (key && (key.includes('zoom') || key.includes('ZM') || key.includes('wc'))) {
          keysToRemove.push(key);
        }
      }
      keysToRemove.forEach(key => sessionStorage.removeItem(key));
      console.log('Cleared session storage keys:', keysToRemove);
      
      // Clear local storage
      const localKeysToRemove = [];
      for (let i = 0; i < localStorage.length; i++) {
        const key = localStorage.key(i);
        if (key && (key.includes('zoom') || key.includes('ZM') || key.includes('wc'))) {
          localKeysToRemove.push(key);
        }
      }
      localKeysToRemove.forEach(key => localStorage.removeItem(key));
      console.log('Cleared local storage keys:', localKeysToRemove);
    } catch (e) {
      console.log('Storage cleanup error (ignoring):', e);
    }
    
    // 3. Wait for everything to settle
    console.log('Waiting for cleanup to complete...');
    await new Promise(resolve => setTimeout(resolve, 2000));
    
    // 4. Create fresh client
    console.log('Creating fresh Zoom client...');
    zoomClientRef.current = ZoomMtgEmbedded.createClient();
    console.log('New client created:', zoomClientRef.current);
    
    // CRITICAL: Check if already in a meeting
    try {
      const currentUser = zoomClientRef.current.getCurrentUser?.();
      const currentMeeting = zoomClientRef.current.getCurrentMeetingInfo?.();
      console.log('Current Zoom state check:', { currentUser, currentMeeting });
      
      if (currentUser || currentMeeting) {
        console.log('⚠️ ZOOM SDK STILL HAS ACTIVE STATE - Forcing cleanup...');
        await zoomClientRef.current.leaveMeeting().catch(() => {});
        zoomClientRef.current = null;
        
        // Set flag to reload page
        sessionStorage.setItem('waddle_left_meeting', 'true');
        setError('Detected active Zoom session. Reloading to clear state...');
        setTimeout(() => window.location.reload(), 1000);
        return;
      }
    } catch (e) {
      console.log('State check error (ignoring):', e);
    }

    // Show the container
    setIsJoined(true);
    
    // Wait for container to be rendered
    await new Promise(resolve => setTimeout(resolve, 800));

    try {
      if (!meetingContainerRef.current) {
        throw new Error('Meeting container not found in DOM');
      }

      console.log('Container found, initializing Zoom SDK...', {
        meetingNumber,
        userName,
        userEmail,
        role,
        containerElement: meetingContainerRef.current.id,
      });

      // Initialize the meeting SDK in the container
      await zoomClientRef.current.init({
        zoomAppRoot: meetingContainerRef.current,
        language: 'en-US',
        customize: {
          video: {
            isResizable: false,
            viewSizes: {
              default: {
                width: '100%',
                height: '100%',
              },
            },
            popper: {
              disableDraggable: false,
            },
          },
        },
      });

      console.log('✅ Zoom SDK initialized successfully, joining meeting...');
      console.log('Join parameters:', {
        meetingNumber,
        userName,
        userEmail,
        role,
        hasPassword: !!password,
        hasSignature: !!signature,
      });

      // Join the meeting
      try {
        await zoomClientRef.current.join({
          signature: signature,
          meetingNumber: meetingNumber,
          password: password,
          userName: userName,
          userEmail: userEmail,
          tk: '',
          zak: '',
        });
        console.log('✅ Successfully joined meeting!');
      } catch (joinError: any) {
        console.error('❌ JOIN ERROR DETAILS:', {
          type: joinError.type,
          reason: joinError.reason,
          errorCode: joinError.errorCode,
          message: joinError.message,
          fullError: joinError,
        });
        throw joinError;
      }
      
      // Update consultation status to in_progress
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      await fetch(`/api/v1/consultations/${consultation.id}/start`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrfToken || '',
        },
        credentials: 'same-origin',
      }).catch(err => console.error('Failed to start consultation:', err));

      // Listen for when meeting ends or user leaves
      zoomClientRef.current.on('meeting-ended', async () => {
        console.log('🔴 Meeting ended event triggered');
        await handleMeetingEnd();
      });

      zoomClientRef.current.on('user-left', async (data: any) => {
        console.log('🔴 User left event:', data);
        // You left the meeting
        if (data.userId === 'self') {
          console.log('🔴 YOU left the meeting via Zoom UI - forcing cleanup and redirect');
          await handleUserLeave();
          
          // CRITICAL: User clicked Zoom's leave button - force full cleanup
          sessionStorage.removeItem('waddle_in_meeting');
          sessionStorage.setItem('waddle_left_meeting', 'true');
          sessionStorage.removeItem('waddle_join_attempts');
          
          // Destroy client
          try {
            await new Promise(resolve => setTimeout(resolve, 500));
            zoomClientRef.current?.destroy?.();
            zoomClientRef.current = null;
            console.log('✅ Destroyed Zoom client after user-left event');
          } catch (e) {
            console.error('Error destroying client:', e);
          }
          
          // Notify backend
          const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
          await fetch(`/api/v1/consultations/${consultation.id}/end`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json',
              'X-CSRF-TOKEN': csrfToken || '',
            },
            credentials: 'same-origin',
          }).catch(err => console.error('Failed to end consultation:', err));
          
          // Redirect to force fresh page
          console.log('🔄 Redirecting to consultations...');
          setTimeout(() => {
            window.location.href = '/consultations';
          }, 1000);
        }
      });

      setIsLoading(false);
      console.log('✅ Join complete!');
    } catch (err: any) {
      console.error('❌ Failed to join meeting:', err);
      
      // Special handling for "already in meeting" error
      if (err.errorCode === 3000 || err.reason?.includes('Already has other meetings')) {
        console.log('🔄 Detected "already in meeting" error - setting reload flag');
        sessionStorage.setItem('waddle_left_meeting', 'true');
        setError('You have an active meeting session. The page will reload to reset the connection...');
        // Auto-reload after showing error
        setTimeout(() => {
          window.location.reload();
        }, 2000);
      } else {
        setError(err.reason || err.message || 'Failed to join meeting. Please try again.');
      }
      
      setIsLoading(false);
      setIsJoined(false);
      hasJoinedRef.current = false; // Reset on error
      sessionStorage.removeItem('waddle_in_meeting');
    }
  };

  const handleMeetingEnd = async () => {
    // Meeting ended - mark consultation as completed
    try {
      await fetch(`/api/v1/consultations/${consultation.id}/end`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        credentials: 'same-origin',
      });
      console.log('Consultation marked as completed');
      // Redirect to consultations page after a short delay
      setTimeout(() => {
        window.location.href = '/consultations';
      }, 2000);
    } catch (error) {
      console.error('Failed to end consultation:', error);
    }
    setIsJoined(false);
  };

  const handleUserLeave = async () => {
    // User left via Zoom UI - cleanup is now handled in the event listener
    console.log('handleUserLeave called');
    setIsJoined(false);
    hasJoinedRef.current = false;
  };

  const leaveMeeting = async () => {
    console.log('=== LEAVE MEETING CALLED ===');
    if (zoomClientRef.current) {
      try {
        // Leave the Zoom meeting
        await zoomClientRef.current.leaveMeeting();
        console.log('✅ Left Zoom meeting');
        
        // Destroy the client to fully clean up
        await new Promise(resolve => setTimeout(resolve, 500));
        zoomClientRef.current?.destroy?.();
        zoomClientRef.current = null;
        console.log('✅ Destroyed Zoom client');
        
        // Clear meeting flag and set left flag
        sessionStorage.removeItem('waddle_in_meeting');
        sessionStorage.setItem('waddle_left_meeting', 'true');
        
        // Notify backend
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        await fetch(`/api/v1/consultations/${consultation.id}/end`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken || '',
          },
          credentials: 'same-origin',
        });
        console.log('✅ Backend notified');
        
        // Update UI
        setIsJoined(false);
        hasJoinedRef.current = false;
        
        // CRITICAL: Redirect with page reload to reset Zoom SDK
        console.log('🔄 Redirecting with page reload to reset Zoom SDK...');
        window.location.href = '/consultations';
      } catch (e) {
        console.error('Error leaving meeting:', e);
        // Still redirect even if there's an error
        sessionStorage.removeItem('waddle_in_meeting');
        sessionStorage.setItem('waddle_left_meeting', 'true');
        window.location.href = '/consultations';
      }
    }
  };

  return (
    <Layout>
      <Head title={`Consultation #${consultation.id} - Meeting`} />
      
      <div className="min-h-screen pt-24 pb-12">
        <div className="container max-w-6xl">
          {/* Header */}
          <div className="mb-6">
            <h1 className="text-2xl font-bold text-foreground">
              Consultation #{consultation.id}
            </h1>
            <p className="text-muted-foreground">
              {role === 1 ? 'You are the host' : `With ${consultation.consultant.user.full_name}`}
            </p>
          </div>

          {/* Error Alert */}
          {error && (
            <Alert variant="destructive" className="mb-6">
              <AlertCircle className="h-4 w-4" />
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          {/* Meeting Container */}
          {!isJoined ? (
            <div className="bg-card border border-border rounded-2xl p-12 text-center">
              {isLoading ? (
                <div className="flex flex-col items-center gap-4">
                  <Loader2 className="h-12 w-12 animate-spin text-primary" />
                  <p className="text-muted-foreground">Joining meeting...</p>
                </div>
              ) : (
                <div className="flex flex-col items-center gap-6">
                  <div className="w-20 h-20 rounded-full bg-primary/10 flex items-center justify-center">
                    <Phone className="h-10 w-10 text-primary" />
                  </div>
                  <div>
                    <h2 className="text-xl font-semibold text-foreground mb-2">
                      Ready to Join
                    </h2>
                    <p className="text-muted-foreground mb-6">
                      Click below to join the video consultation with{' '}
                      {role === 1 ? consultation.user.full_name : consultation.consultant.user.full_name}
                    </p>
                  </div>
                  <Button 
                    size="lg" 
                    onClick={joinMeeting}
                    disabled={isLoading || isJoined}
                  >
                    <Phone className="mr-2 h-5 w-5" />
                    Join Meeting
                  </Button>
                </div>
              )}
            </div>
          ) : (
            <div className="space-y-4">
              {/* Zoom will render its UI here */}
              <div 
                ref={meetingContainerRef}
                id="meetingSDKElement"
                className="w-full h-[calc(100vh-280px)] bg-black rounded-xl overflow-hidden"
                style={{ minHeight: '700px' }}
              />
              
              {/* Leave button */}
              <div className="flex justify-center">
                <Button variant="destructive" onClick={leaveMeeting}>
                  <PhoneOff className="mr-2 h-5 w-5" />
                  Leave Meeting
                </Button>
              </div>
            </div>
          )}

          {/* Meeting Info */}
          <div className="mt-8 p-4 bg-muted/50 rounded-xl">
            <h3 className="font-medium text-foreground mb-2">Meeting Information</h3>
            <div className="grid grid-cols-2 gap-4 text-sm">
              <div>
                <span className="text-muted-foreground">Meeting ID:</span>
                <span className="ml-2 font-mono">{meetingNumber}</span>
              </div>
              <div>
                <span className="text-muted-foreground">Status:</span>
                <span className="ml-2 capitalize">{consultation.status}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Layout>
  );
}
