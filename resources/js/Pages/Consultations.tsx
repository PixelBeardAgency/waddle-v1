import React, { useState } from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import { Layout } from '@/Components/layout';
import { Button } from '@/Components/ui/Button.tsx';
import { Card, CardContent } from '@/Components/ui/Card.tsx';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/Dialog.tsx';
import { Label } from '@/Components/ui/Label.tsx';
import { Alert, AlertDescription } from '@/Components/ui/Alert.tsx';
import { MessageSquare, Plus, Clock, CheckCircle, AlertCircle, Video, Calendar, Loader2 } from 'lucide-react';
import { cn } from '@/lib/utils.ts';
import type { PageProps } from '@/types';

interface ConsultationRequest {
  id: number;
  problem_description: string;
  tech_stack: string[];
  status: string;
  created_at: string;
  proposed_time?: string;
  counter_proposed_time?: string;
  agreed_time?: string;
  zoom_meeting_id?: string;
  matched_consultant?: {
    id: number;
    user: {
      full_name: string;
    };
  };
}

interface Consultation {
  id: number;
  zoom_meeting_id?: string;
  status: string;
  scheduled_at?: string;
  created_at: string;
  consultant: {
    id: number;
    user: {
      full_name: string;
    };
  };
}

interface ConsultationsPageProps extends PageProps {
  requests: ConsultationRequest[];
  consultations: Consultation[];
}

export default function Consultations() {
  const { auth, requests, consultations } = usePage<ConsultationsPageProps>().props;
  const [activeTab, setActiveTab] = useState<'all' | 'active' | 'completed'>('all');
  const [showAcceptModal, setShowAcceptModal] = useState(false);
  const [showCounterModal, setShowCounterModal] = useState(false);
  const [selectedRequest, setSelectedRequest] = useState<ConsultationRequest | null>(null);
  const [counterTime, setCounterTime] = useState('');
  const [counterReason, setCounterReason] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const allRequests = requests || [];
  const allConsultations = consultations || [];

  // Debug logging
  console.log('Consultations Page Data:', {
    requests: allRequests,
    consultations: allConsultations,
    requestsCount: allRequests.length,
    consultationsCount: allConsultations.length
  });

  // Filter based on active tab
  const getFilteredItems = () => {
    const activeStatuses = ['pending', 'matching', 'invited', 'matched', 'time_proposed', 'time_counter_proposed', 'scheduled', 'ready', 'in_progress'];
    const completedStatuses = ['completed', 'cancelled'];

    if (activeTab === 'active') {
      return {
        requests: allRequests.filter(r => activeStatuses.includes(r.status)),
        consultations: allConsultations.filter(c => activeStatuses.includes(c.status)),
      };
    } else if (activeTab === 'completed') {
      return {
        requests: allRequests.filter(r => completedStatuses.includes(r.status)),
        consultations: allConsultations.filter(c => completedStatuses.includes(c.status)),
      };
    }
    return { requests: allRequests, consultations: allConsultations };
  };

  const { requests: filteredRequests, consultations: filteredConsultations } = getFilteredItems();
  const hasAnyItems = filteredRequests.length > 0 || filteredConsultations.length > 0;

  const getStatusBadge = (status: string) => {
    const statusConfig: Record<string, { label: string; color: string }> = {
      pending: { label: 'Finding Consultant', color: 'bg-yellow-500/10 text-yellow-600' },
      matching: { label: 'Matching', color: 'bg-yellow-500/10 text-yellow-600' },
      invited: { label: 'Consultants Invited', color: 'bg-blue-500/10 text-blue-600' },
      matched: { label: 'Matched', color: 'bg-blue-500/10 text-blue-600' },
      time_proposed: { label: 'Time Proposed', color: 'bg-purple-500/10 text-purple-600' },
      time_counter_proposed: { label: 'Awaiting Response', color: 'bg-orange-500/10 text-orange-600' },
      scheduled: { label: 'Scheduled', color: 'bg-green-500/10 text-green-600' },
      ready: { label: 'Ready to Join', color: 'bg-green-500/10 text-green-600' },
      in_progress: { label: 'In Progress', color: 'bg-purple-500/10 text-purple-600' },
      completed: { label: 'Completed', color: 'bg-gray-500/10 text-gray-600' },
      cancelled: { label: 'Cancelled', color: 'bg-red-500/10 text-red-600' },
    };

    const config = statusConfig[status] || { label: status, color: 'bg-gray-500/10 text-gray-600' };
    return (
      <span className={cn('px-2 py-1 rounded-full text-xs font-medium', config.color)}>
        {config.label}
      </span>
    );
  };

  const handleAcceptTime = () => {
    if (!selectedRequest) return;

    setError(null);
    setIsSubmitting(true);

    router.post(`/consultations/${selectedRequest.id}/accept-time`, {}, {
      onSuccess: () => {
        setShowAcceptModal(false);
        setSelectedRequest(null);
      },
      onError: (errors) => {
        setError(Object.values(errors)[0] as string);
      },
      onFinish: () => {
        setIsSubmitting(false);
      },
    });
  };

  const handleCounterPropose = () => {
    if (!selectedRequest) return;

    setError(null);
    setIsSubmitting(true);

    router.post(`/consultations/${selectedRequest.id}/counter-time`, {
      counter_proposed_time: counterTime,
      counter_proposal_reason: counterReason,
    }, {
      onSuccess: () => {
        setShowCounterModal(false);
        setSelectedRequest(null);
        setCounterTime('');
        setCounterReason('');
      },
      onError: (errors) => {
        setError(Object.values(errors)[0] as string);
      },
      onFinish: () => {
        setIsSubmitting(false);
      },
    });
  };

  const renderContent = () => {
    if (!hasAnyItems) {
      return (
        <Card>
          <CardContent className="p-12">
            <div className="flex flex-col items-center justify-center text-center">
              <MessageSquare className="h-16 w-16 text-muted-foreground/50 mb-6" />
              <h3 className="text-xl font-semibold mb-2">
                {activeTab === 'all' ? 'No consultations yet' : `No ${activeTab} consultations`}
              </h3>
              <p className="text-muted-foreground mb-6 max-w-md">
                {activeTab === 'all'
                  ? "Start your first consultation to get expert help with your coding problems. Our consultants are ready to assist you!"
                  : `You don't have any ${activeTab} consultations at the moment.`}
              </p>
              {activeTab === 'all' && (
                <Link href="/consultations/new">
                  <Button size="lg">
                    <Plus className="h-5 w-5 mr-2" />
                    Start a Consultation
                  </Button>
                </Link>
              )}
            </div>
          </CardContent>
        </Card>
      );
    }

    return (
      <div className="space-y-4">
        {/* Consultation Requests */}
        {filteredRequests.map((request) => (
          <Card key={`request-${request.id}`} className="hover:border-primary/50 transition-colors">
            <CardContent className="p-6">
              <div className="flex items-start justify-between gap-4">
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-3 mb-2">
                    <AlertCircle className="h-5 w-5 text-primary flex-shrink-0" />
                    <h3 className="font-semibold text-lg">Consultation Request #{request.id}</h3>
                    {getStatusBadge(request.status)}
                  </div>
                  <p className="text-muted-foreground mb-3 line-clamp-2">
                    {request.problem_description}
                  </p>
                  <div className="flex flex-wrap gap-2 mb-3">
                    {request.tech_stack.map((tech) => (
                      <span
                        key={tech}
                        className="px-2 py-1 rounded-md bg-muted text-xs font-medium"
                      >
                        {tech}
                      </span>
                    ))}
                  </div>
                  <div className="flex items-center gap-4 text-sm text-muted-foreground">
                    <span className="flex items-center gap-1">
                      <Clock className="h-4 w-4" />
                      {new Date(request.created_at).toLocaleDateString()}
                    </span>
                    {request.matched_consultant && (
                      <span>Matched with {request.matched_consultant.user.full_name}</span>
                    )}
                  </div>

                  {/* Time Proposed */}
                  {request.status === 'time_proposed' && request.proposed_time && (
                    <div className="mt-4 p-3 bg-muted rounded-lg">
                      <p className="text-sm font-medium mb-1">Proposed Meeting Time:</p>
                      <p className="text-sm flex items-center gap-2">
                        <Calendar className="h-4 w-4 text-primary" />
                        {new Date(request.proposed_time).toLocaleString()}
                      </p>
                      <div className="flex gap-2 mt-3">
                        <Button
                          size="sm"
                          onClick={() => {
                            setSelectedRequest(request);
                            setShowAcceptModal(true);
                          }}
                        >
                          <CheckCircle className="h-4 w-4 mr-1" />
                          Accept
                        </Button>
                        <Button
                          size="sm"
                          variant="outline"
                          onClick={() => {
                            setSelectedRequest(request);
                            setShowCounterModal(true);
                          }}
                        >
                          Counter-Propose
                        </Button>
                      </div>
                    </div>
                  )}

                  {/* Scheduled/Ready with Join Button - Only show for non-completed */}
                  {request.status !== 'completed' && request.status !== 'cancelled' && (request.status === 'ready' || (request.status === 'scheduled' && request.agreed_time)) && (
                    <div className="mt-4 flex items-center gap-4">
                      {request.agreed_time && (
                        <div className="flex items-center gap-2 text-sm text-muted-foreground">
                          <Calendar className="h-4 w-4" />
                          <span>{new Date(request.agreed_time).toLocaleString()}</span>
                        </div>
                      )}
                      {request.status === 'ready' && request.zoom_meeting_id && (
                        <a href={`/consultation-requests/${request.id}/meeting`}>
                          <Button size="sm" className="gap-2">
                            <Video className="h-4 w-4" />
                            Join Meeting
                          </Button>
                        </a>
                      )}
                    </div>
                  )}
                </div>
              </div>
            </CardContent>
          </Card>
        ))}

        {/* Active Consultations */}
        {filteredConsultations.map((consultation) => (
          <Card key={`consultation-${consultation.id}`} className="hover:border-primary/50 transition-colors">
            <CardContent className="p-6">
              <div className="flex items-start justify-between gap-4">
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-3 mb-2">
                    <MessageSquare className="h-5 w-5 text-primary flex-shrink-0" />
                    <h3 className="font-semibold text-lg">Consultation #{consultation.id}</h3>
                    {getStatusBadge(consultation.status)}
                  </div>
                  <p className="text-muted-foreground mb-3">
                    with {consultation.consultant.user.full_name}
                  </p>
                  <div className="flex items-center gap-4 text-sm text-muted-foreground mb-4">
                    <span className="flex items-center gap-1">
                      <Clock className="h-4 w-4" />
                      {consultation.scheduled_at
                        ? new Date(consultation.scheduled_at).toLocaleString()
                        : new Date(consultation.created_at).toLocaleDateString()}
                    </span>
                  </div>
                  {consultation.zoom_meeting_id && (
                    <Link href={`/consultations/${consultation.id}/meeting`}>
                      <Button size="sm">
                        Join Meeting
                      </Button>
                    </Link>
                  )}
                </div>
              </div>
            </CardContent>
          </Card>
        ))}
      </div>
    );
  };

  return (
    <Layout>
      <div className="container py-8 space-y-8">
        {/* Header */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <h1 className="text-3xl font-bold">My Consultations</h1>
            <p className="text-muted-foreground mt-1">
              View and manage your consultation history
            </p>
          </div>
          <Link href="/consultations/new">
            <Button>
              <Plus className="h-4 w-4 mr-2" />
              New Consultation
            </Button>
          </Link>
        </div>

        {/* Tabs */}
        <div className="flex gap-4 border-b">
          <button
            onClick={() => setActiveTab('all')}
            className={cn(
              "pb-2 px-1 text-sm font-medium border-b-2 transition-colors",
              activeTab === 'all'
                ? "border-primary text-primary"
                : "border-transparent text-muted-foreground hover:text-foreground"
            )}
          >
            All
          </button>
          <button
            onClick={() => setActiveTab('active')}
            className={cn(
              "pb-2 px-1 text-sm font-medium border-b-2 transition-colors",
              activeTab === 'active'
                ? "border-primary text-primary"
                : "border-transparent text-muted-foreground hover:text-foreground"
            )}
          >
            Active
          </button>
          <button
            onClick={() => setActiveTab('completed')}
            className={cn(
              "pb-2 px-1 text-sm font-medium border-b-2 transition-colors",
              activeTab === 'completed'
                ? "border-primary text-primary"
                : "border-transparent text-muted-foreground hover:text-foreground"
            )}
          >
            Completed
          </button>
        </div>

        {/* Content */}
        {renderContent()}

        {/* Stats */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          <Card>
            <CardContent className="p-6">
              <div className="flex items-center gap-4">
                <div className="p-3 rounded-lg bg-blue-500/10">
                  <MessageSquare className="h-6 w-6 text-blue-500" />
                </div>
                <div>
                  <p className="text-2xl font-bold">{allConsultations.length}</p>
                  <p className="text-sm text-muted-foreground">Total Consultations</p>
                </div>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="p-6">
              <div className="flex items-center gap-4">
                <div className="p-3 rounded-lg bg-green-500/10">
                  <Clock className="h-6 w-6 text-green-500" />
                </div>
                <div>
                  <p className="text-2xl font-bold">0h</p>
                  <p className="text-sm text-muted-foreground">Time Consulted</p>
                </div>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="p-6">
              <div className="flex items-center gap-4">
                <div className="p-3 rounded-lg bg-purple-500/10">
                  <CheckCircle className="h-6 w-6 text-purple-500" />
                </div>
                <div>
                  <p className="text-2xl font-bold">
                    {allConsultations.filter(c => c.status === 'completed').length}
                  </p>
                  <p className="text-sm text-muted-foreground">Problems Solved</p>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Accept Time Modal */}
        <Dialog open={showAcceptModal} onOpenChange={setShowAcceptModal}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Accept Proposed Time</DialogTitle>
            </DialogHeader>

            {error && (
              <Alert variant="destructive">
                <AlertCircle className="h-4 w-4" />
                <AlertDescription>{error}</AlertDescription>
              </Alert>
            )}

            {selectedRequest && selectedRequest.proposed_time && (
              <div>
                <p className="text-sm text-muted-foreground mb-2">Meeting scheduled for:</p>
                <p className="text-lg font-semibold">
                  {new Date(selectedRequest.proposed_time).toLocaleString()}
                </p>
              </div>
            )}

            <DialogFooter>
              <Button variant="outline" onClick={() => setShowAcceptModal(false)} disabled={isSubmitting}>
                Cancel
              </Button>
              <Button onClick={handleAcceptTime} disabled={isSubmitting}>
                {isSubmitting && <Loader2 className="h-4 w-4 mr-2 animate-spin" />}
                Confirm Time
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>

        {/* Counter-Propose Time Modal */}
        <Dialog open={showCounterModal} onOpenChange={setShowCounterModal}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Propose Different Time</DialogTitle>
            </DialogHeader>

            {error && (
              <Alert variant="destructive">
                <AlertCircle className="h-4 w-4" />
                <AlertDescription>{error}</AlertDescription>
              </Alert>
            )}

            <div className="space-y-4">
              <div>
                <Label htmlFor="counter_time">Your Preferred Time *</Label>
                <input
                  id="counter_time"
                  type="datetime-local"
                  value={counterTime}
                  onChange={(e) => setCounterTime(e.target.value)}
                  className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                  required
                />
              </div>

              <div>
                <Label htmlFor="counter_reason">Reason (Optional)</Label>
                <textarea
                  id="counter_reason"
                  value={counterReason}
                  onChange={(e) => setCounterReason(e.target.value)}
                  placeholder="Why does the proposed time not work for you?"
                  className="flex min-h-[100px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                  maxLength={500}
                />
              </div>
            </div>

            <DialogFooter>
              <Button variant="outline" onClick={() => setShowCounterModal(false)} disabled={isSubmitting}>
                Cancel
              </Button>
              <Button onClick={handleCounterPropose} disabled={!counterTime || isSubmitting}>
                {isSubmitting && <Loader2 className="h-4 w-4 mr-2 animate-spin" />}
                Send Counter-Proposal
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>
    </Layout>
  );
}
