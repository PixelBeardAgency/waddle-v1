import React from 'react';
import { Head, Link } from '@inertiajs/react';
import { Layout } from '@/Components/layout';
import { Button } from '@/Components/ui/Button';
import { Alert, AlertDescription } from '@/Components/ui/Alert';
import { AlertCircle, ArrowLeft, CheckCircle, Clock, XCircle } from 'lucide-react';
import type { PageProps } from '@/types';

interface MeetingUnavailableProps extends PageProps {
  reason: 'not_created' | 'completed' | 'expired' | 'not_active';
  message: string;
  consultation_id: number;
}

export default function MeetingUnavailable({ reason, message, consultation_id }: MeetingUnavailableProps) {
  const getIcon = () => {
    switch (reason) {
      case 'completed':
        return <CheckCircle className="h-20 w-20 text-green-500" />;
      case 'expired':
        return <Clock className="h-20 w-20 text-orange-500" />;
      case 'not_created':
        return <Clock className="h-20 w-20 text-blue-500" />;
      case 'not_active':
      default:
        return <XCircle className="h-20 w-20 text-red-500" />;
    }
  };

  const getTitle = () => {
    switch (reason) {
      case 'completed':
        return 'Meeting Completed';
      case 'expired':
        return 'Meeting Expired';
      case 'not_created':
        return 'Meeting Not Ready';
      case 'not_active':
      default:
        return 'Meeting Unavailable';
    }
  };

  const getDescription = () => {
    switch (reason) {
      case 'completed':
        return 'This consultation has ended. We hope it was helpful! You can view the summary and any shared files in your consultations page.';
      case 'expired':
        return 'This meeting room has expired and is no longer available. Meeting rooms are typically available for a limited time after creation.';
      case 'not_created':
        return 'The meeting room hasn\'t been created yet. Meeting rooms are typically created 5 minutes before the scheduled time.';
      case 'not_active':
      default:
        return 'This meeting is not currently active. Please check the consultation status or contact support if you believe this is an error.';
    }
  };

  return (
    <Layout>
      <Head title={getTitle()} />
      
      <div className="min-h-screen pt-24 pb-12 flex items-center justify-center">
        <div className="container max-w-2xl">
          <div className="bg-card border border-border rounded-2xl p-12 text-center">
            {/* Icon */}
            <div className="flex justify-center mb-6">
              {getIcon()}
            </div>

            {/* Title */}
            <h1 className="text-3xl font-bold text-foreground mb-4">
              {getTitle()}
            </h1>

            {/* Message */}
            <p className="text-muted-foreground text-lg mb-2">
              {message}
            </p>

            {/* Description */}
            <p className="text-muted-foreground mb-8">
              {getDescription()}
            </p>

            {/* Alert for additional info */}
            {reason === 'not_created' && (
              <Alert className="mb-6 text-left">
                <AlertCircle className="h-4 w-4" />
                <AlertDescription>
                  Meeting rooms are automatically created 5 minutes before your scheduled consultation time. 
                  Please check back closer to your appointment.
                </AlertDescription>
              </Alert>
            )}

            {/* Actions */}
            <div className="flex flex-col sm:flex-row gap-4 justify-center">
              <Button asChild>
                <Link href="/consultations">
                  <ArrowLeft className="mr-2 h-4 w-4" />
                  Back to Consultations
                </Link>
              </Button>
              
              {reason === 'expired' && (
                <Button variant="outline" asChild>
                  <Link href="/support">
                    Contact Support
                  </Link>
                </Button>
              )}
            </div>

            {/* Consultation ID reference */}
            <div className="mt-8 pt-6 border-t border-border">
              <p className="text-sm text-muted-foreground">
                Consultation ID: <span className="font-mono font-medium">#{consultation_id}</span>
              </p>
            </div>
          </div>
        </div>
      </div>
    </Layout>
  );
}
