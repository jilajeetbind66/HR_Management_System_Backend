<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AuditLog;
use App\Support\RoleAccess;
use App\Support\UserNotification;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HelpdeskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tickets = $this->visibleTicketQuery($request)
            ->orderByDesc('helpdesk_tickets.id')
            ->get()
            ->map(fn ($ticket) => $this->addSlaSummary($ticket));

        return response()->json(['data' => $tickets]);
    }

    public function show(Request $request, int $ticketId): JsonResponse
    {
        return response()->json(['data' => $this->loadTicket($request, $ticketId, true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'category' => 'required|in:IT Support,HR Query,Payroll,Facilities',
            'priority' => 'required|in:Low,Medium,High,Urgent',
        ]);
        if (
            in_array($request->user()->role, ['hr_admin', 'hr_executive'], true)
            && $validated['category'] !== 'HR Query'
        ) {
            abort(403, 'HR accounts may raise HR Query tickets only.');
        }

        $slaHours = [
            'Low' => 72,
            'Medium' => 24,
            'High' => 8,
            'Urgent' => 2,
        ][$validated['priority']];
        $assignedTeam = [
            'IT Support' => 'IT Support',
            'HR Query' => 'HR Support',
            'Payroll' => 'Payroll Support',
            'Facilities' => 'Facilities Support',
        ][$validated['category']];

        $ticketId = DB::transaction(function () use ($request, $validated, $slaHours, $assignedTeam) {
            $ticketCode = 'HD-'.Str::upper(Str::random(10));
            $id = DB::table('helpdesk_tickets')->insertGetId([
                'ticket_code' => $ticketCode,
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'category' => $validated['category'],
                'priority' => $validated['priority'],
                'created_by' => $request->user()->name,
                'created_by_user_id' => $request->user()->id,
                'assigned_to' => $assignedTeam,
                'status' => 'Open',
                'sla_hours_left' => $slaHours,
                'sla_due_at' => now()->addHours($slaHours),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            AuditLog::record($request, 'TICKET_CREATED', 'Helpdesk', "Created ticket #{$id}");

            UserNotification::sendToRole('super_admin', 'helpdesk.created', "New {$validated['category']} Ticket ({$validated['priority']})", "{$request->user()->name} raised ticket {$ticketCode}: {$validated['title']}", $request->user()->id);
            if ($validated['category'] === 'HR Query') {
                UserNotification::sendToRole('hr_admin', 'helpdesk.created', "New HR Query Ticket ({$validated['priority']})", "{$request->user()->name} raised ticket {$ticketCode}: {$validated['title']}", $request->user()->id);
            }

            return $id;
        });

        return response()->json(['data' => $this->loadTicket($request, $ticketId, true)], 201);
    }

    public function addMessage(Request $request, int $ticketId): JsonResponse
    {
        $validated = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        DB::transaction(function () use ($request, $ticketId, $validated) {
            $ticket = $this->findVisibleTicket($request, $ticketId, true);
            abort_if($ticket->status === 'Closed', 409, 'Closed tickets cannot receive new replies.');

            $this->insertMessage($ticketId, $request, 'comment', $validated['body']);
            DB::table('helpdesk_tickets')->where('id', $ticketId)->update([
                'updated_at' => now(),
            ]);
            AuditLog::record($request, 'TICKET_MESSAGE_ADDED', 'Helpdesk', "Added a reply to ticket #{$ticketId}");

            if ((int) $ticket->created_by_user_id !== (int) $request->user()->id) {
                UserNotification::send(
                    (int) $ticket->created_by_user_id,
                    'helpdesk.replied',
                    "Ticket Reply: {$ticket->ticket_code}",
                    "{$request->user()->name} replied: " . Str::limit($validated['body'], 60)
                );
            } else {
                UserNotification::sendToRole(
                    'super_admin',
                    'helpdesk.replied',
                    "Ticket Reply: {$ticket->ticket_code}",
                    "{$request->user()->name} replied to ticket {$ticket->ticket_code}",
                    $request->user()->id
                );
            }
        });

        return response()->json(['data' => $this->loadTicket($request, $ticketId, true)]);
    }

    public function updateStatus(Request $request, int $ticketId): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:Open,In Progress,Resolved,Closed',
        ]);

        DB::transaction(function () use ($request, $ticketId, $validated) {
            $ticket = $this->findVisibleTicket($request, $ticketId, true);
            $user = $request->user();
            $isRequester = (int) $ticket->created_by_user_id === (int) $user->id;

            if (! $this->canManageTicket($user->role, $ticket->category)) {
                abort_unless(
                    $isRequester && $ticket->status === 'Resolved' && $validated['status'] === 'Closed',
                    403
                );
            } else {
                $allowedTransitions = [
                    'Open' => ['In Progress', 'Resolved'],
                    'In Progress' => ['Resolved'],
                    'Resolved' => ['In Progress', 'Closed'],
                    'Closed' => [],
                ];
                abort_unless(
                    in_array($validated['status'], $allowedTransitions[$ticket->status] ?? [], true),
                    409,
                    'This ticket status transition is not allowed.'
                );
            }

            $status = $validated['status'];
            DB::table('helpdesk_tickets')->where('id', $ticketId)->update([
                'status' => $status,
                'resolved_at' => $status === 'Resolved'
                    ? now()
                    : ($status === 'In Progress' ? null : $ticket->resolved_at),
                'closed_at' => $status === 'Closed' ? now() : null,
                'updated_at' => now(),
            ]);
            $this->insertMessage(
                $ticketId,
                $request,
                'status',
                "Status changed from {$ticket->status} to {$status}."
            );
            AuditLog::record(
                $request,
                'TICKET_STATUS_UPDATED',
                'Helpdesk',
                "Updated ticket #{$ticketId} status to {$status}"
            );

            if ((int) $ticket->created_by_user_id !== (int) $request->user()->id) {
                UserNotification::send(
                    (int) $ticket->created_by_user_id,
                    'helpdesk.status_updated',
                    "Ticket Status: {$status}",
                    "Your ticket {$ticket->ticket_code} status has been updated to {$status}."
                );
            }
        });

        return response()->json(['data' => $this->loadTicket($request, $ticketId, true)]);
    }

    private function visibleTicketQuery(Request $request): Builder
    {
        $user = $request->user();
        $query = DB::table('helpdesk_tickets');

        if (in_array($user->role, ['hr_admin', 'hr_executive'], true)) {
            $query->where(function (Builder $builder) use ($user) {
                $builder->where('category', 'HR Query')
                    ->orWhere('created_by_user_id', $user->id);
            });
        } elseif ($user->role === 'manager') {
            $employeeIds = RoleAccess::visibleEmployeeIds($user);
            $userIds = DB::table('users')
                ->whereIn('employee_id', $employeeIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $userIds[] = (int) $user->id;
            $query->whereIn('created_by_user_id', array_values(array_unique($userIds)));
        } elseif ($user->role !== 'super_admin') {
            $query->where('created_by_user_id', $user->id);
        }

        return $query->select('helpdesk_tickets.*');
    }

    private function findVisibleTicket(Request $request, int $ticketId, bool $lock = false): object
    {
        $query = $this->visibleTicketQuery($request)
            ->where('helpdesk_tickets.id', $ticketId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $ticket = $query->first();
        abort_unless($ticket, 404, 'Ticket not found.');

        return $ticket;
    }

    private function loadTicket(Request $request, int $ticketId, bool $includeMessages): object
    {
        $ticket = $this->addSlaSummary($this->findVisibleTicket($request, $ticketId));
        if ($includeMessages) {
            $ticket->messages = DB::table('helpdesk_ticket_messages')
                ->where('ticket_id', $ticketId)
                ->orderBy('id')
                ->get();
        }

        return $ticket;
    }

    private function addSlaSummary(object $ticket): object
    {
        if (! $ticket->sla_due_at) {
            $ticket->sla_status = 'Unknown';
            $ticket->sla_hours_overdue = 0;

            return $ticket;
        }

        $deadline = Carbon::parse($ticket->sla_due_at);
        $remainingSeconds = $deadline->getTimestamp() - Carbon::now()->getTimestamp();
        $ticket->sla_hours_left = max(0, (int) ceil($remainingSeconds / 3600));
        $ticket->sla_hours_overdue = $remainingSeconds < 0
            ? max(1, (int) ceil(abs($remainingSeconds) / 3600))
            : 0;

        $completionAt = $ticket->resolved_at;
        if (! $completionAt && in_array($ticket->status, ['Resolved', 'Closed'], true)) {
            $completionAt = $ticket->updated_at;
        }

        if ($completionAt) {
            $ticket->sla_status = Carbon::parse($completionAt)->lessThanOrEqualTo($deadline)
                ? 'Met'
                : 'Breached';
        } elseif ($remainingSeconds < 0) {
            $ticket->sla_status = 'Overdue';
        } elseif ($remainingSeconds <= 4 * 3600) {
            $ticket->sla_status = 'Due soon';
        } else {
            $ticket->sla_status = 'On track';
        }

        return $ticket;
    }

    private function canManageTicket(string $role, string $category): bool
    {
        return $role === 'super_admin'
            || ($role === 'hr_admin' && $category === 'HR Query');
    }

    private function insertMessage(int $ticketId, Request $request, string $type, string $body): void
    {
        DB::table('helpdesk_ticket_messages')->insert([
            'ticket_id' => $ticketId,
            'created_by_user_id' => $request->user()->id,
            'created_by' => $request->user()->name,
            'type' => $type,
            'body' => $body,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
