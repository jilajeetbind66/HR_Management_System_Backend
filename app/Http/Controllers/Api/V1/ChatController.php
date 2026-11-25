<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    /**
     * Get chat threads / conversations.
     * Super Admin sees all employees with thread stats.
     * Employees see their thread with Super Admin.
     */
    public function getThreads(Request $request): JsonResponse
    {
        $currentUser = $request->user();
        $isSuperAdmin = $currentUser->role === 'super_admin';

        if ($isSuperAdmin) {
            // Fetch all users that are employees/staff
            $employees = DB::table('users')
                ->where('users.id', '<>', $currentUser->id)
                ->whereNull('users.deleted_at')
                ->leftJoin('employees', 'employees.id', '=', 'users.employee_id')
                ->leftJoin('branches', 'branches.id', '=', 'employees.branch_id')
                ->leftJoin('chat_threads', function ($join) use ($currentUser) {
                    $join->on('chat_threads.employee_id', '=', 'users.id')
                         ->where('chat_threads.admin_id', '=', $currentUser->id);
                })
                ->select([
                    'users.id as user_id',
                    'users.name',
                    'users.email',
                    'users.role',
                    'employees.id as employee_record_id',
                    'employees.employee_code',
                    'employees.designation',
                    'employees.department',
                    'employees.avatar',
                    'employees.status as employee_status',
                    'branches.name as branch_name',
                    'chat_threads.id as thread_id',
                    'chat_threads.last_message',
                    'chat_threads.last_message_at',
                    'chat_threads.last_sender_id',
                    'chat_threads.unread_admin_count as unread_count',
                ])
                ->orderByRaw('chat_threads.last_message_at IS NULL, chat_threads.last_message_at DESC')
                ->orderBy('users.name', 'ASC')
                ->get();

            return response()->json([
                'data' => $employees,
            ]);
        }

        // For non-super_admin (Employee, Manager, etc.):
        // Find or provide the thread with Super Admin
        $superAdmin = User::where('role', 'super_admin')->first();
        if (!$superAdmin) {
            return response()->json(['data' => []]);
        }

        $thread = ChatThread::where('admin_id', $superAdmin->id)
            ->where('employee_id', $currentUser->id)
            ->first();

        $threadData = [
            'thread_id' => $thread?->id,
            'user_id' => $superAdmin->id,
            'name' => $superAdmin->name,
            'email' => $superAdmin->email,
            'role' => 'super_admin',
            'employee_code' => 'ADMIN',
            'designation' => 'Super Administrator',
            'department' => 'Administration',
            'avatar' => null,
            'branch_name' => 'Headquarters',
            'last_message' => $thread?->last_message,
            'last_message_at' => $thread?->last_message_at,
            'last_sender_id' => $thread?->last_sender_id,
            'unread_count' => $thread?->unread_employee_count ?? 0,
        ];

        return response()->json([
            'data' => [$threadData],
        ]);
    }

    /**
     * Get messages for a conversation thread.
     * Supports ?after_id=X for incremental polling.
     */
    public function getMessages(Request $request, int $threadId): JsonResponse
    {
        $currentUser = $request->user();
        $thread = ChatThread::findOrFail($threadId);

        // Security authorization check: User must be admin or employee in this thread
        if ($currentUser->role !== 'super_admin' && $currentUser->id !== $thread->employee_id && $currentUser->id !== $thread->admin_id) {
            abort(403, 'Unauthorized access to this chat thread.');
        }

        $query = ChatMessage::where('thread_id', $threadId);

        $afterId = $request->query('after_id');
        if ($afterId && is_numeric($afterId)) {
            $query->where('id', '>', (int) $afterId);
        }

        $messages = $query->orderBy('id', 'ASC')->limit(100)->get();

        // Mark incoming messages as read
        $unreadIncomingIds = $messages->where('receiver_id', $currentUser->id)
            ->where('is_read', false)
            ->pluck('id')
            ->all();

        if (!empty($unreadIncomingIds)) {
            ChatMessage::whereIn('id', $unreadIncomingIds)->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

            // Reset unread count on thread
            if ($currentUser->role === 'super_admin' || $currentUser->id === $thread->admin_id) {
                $thread->update(['unread_admin_count' => 0]);
            } else {
                $thread->update(['unread_employee_count' => 0]);
            }
        }

        return response()->json([
            'data' => $messages,
            'thread' => [
                'id' => $thread->id,
                'admin_id' => $thread->admin_id,
                'employee_id' => $thread->employee_id,
                'last_message_at' => $thread->last_message_at,
            ],
        ]);
    }

    /**
     * Send a new chat message.
     */
    public function sendMessage(Request $request): JsonResponse
    {
        $currentUser = $request->user();

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'receiver_id' => ['nullable', 'integer', 'exists:users,id'],
            'thread_id' => ['nullable', 'integer', 'exists:chat_threads,id'],
        ]);

        $messageText = trim($validated['message']);
        $thread = null;
        $receiver = null;

        if (!empty($validated['thread_id'])) {
            $thread = ChatThread::findOrFail($validated['thread_id']);
            if ($currentUser->role !== 'super_admin' && $currentUser->id !== $thread->employee_id && $currentUser->id !== $thread->admin_id) {
                abort(403, 'Unauthorized thread access.');
            }
            $receiverId = $currentUser->id === $thread->admin_id ? $thread->employee_id : $thread->admin_id;
            $receiver = User::findOrFail($receiverId);
        } else {
            // Find or create thread by receiver_id
            $receiverId = $validated['receiver_id'] ?? null;

            if ($currentUser->role === 'super_admin') {
                abort_unless($receiverId, 422, 'Receiver user ID is required.');
                $receiver = User::findOrFail($receiverId);
                $thread = ChatThread::firstOrCreate(
                    ['admin_id' => $currentUser->id, 'employee_id' => $receiver->id],
                    ['unread_admin_count' => 0, 'unread_employee_count' => 0]
                );
            } else {
                // If regular employee is initiating, receiver is Super Admin
                if (!$receiverId) {
                    $superAdmin = User::where('role', 'super_admin')->firstOrFail();
                    $receiver = $superAdmin;
                } else {
                    $receiver = User::findOrFail($receiverId);
                }

                $thread = ChatThread::firstOrCreate(
                    ['admin_id' => $receiver->id, 'employee_id' => $currentUser->id],
                    ['unread_admin_count' => 0, 'unread_employee_count' => 0]
                );
            }
        }

        // Create the message
        $chatMessage = ChatMessage::create([
            'thread_id' => $thread->id,
            'sender_id' => $currentUser->id,
            'receiver_id' => $receiver->id,
            'message' => $messageText,
            'is_read' => false,
        ]);

        // Update the thread stats
        $isSenderAdmin = ($currentUser->role === 'super_admin' || $currentUser->id === $thread->admin_id);
        $thread->last_message = Str::limit($messageText, 150);
        $thread->last_message_at = now();
        $thread->last_sender_id = $currentUser->id;

        if ($isSenderAdmin) {
            $thread->increment('unread_employee_count');
        } else {
            $thread->increment('unread_admin_count');
        }
        $thread->save();

        // Create in-app notification for the recipient
        DB::table('user_notifications')->insert([
            'user_id' => $receiver->id,
            'event_key' => 'chat.new_message',
            'title' => 'New message from ' . $currentUser->name,
            'message' => Str::limit($messageText, 120),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Message sent successfully.',
            'data' => $chatMessage,
            'thread_id' => $thread->id,
        ], 201);
    }

    /**
     * Mark thread messages as read.
     */
    public function markAsRead(Request $request, int $threadId): JsonResponse
    {
        $currentUser = $request->user();
        $thread = ChatThread::findOrFail($threadId);

        if ($currentUser->role !== 'super_admin' && $currentUser->id !== $thread->employee_id && $currentUser->id !== $thread->admin_id) {
            abort(403, 'Unauthorized thread access.');
        }

        ChatMessage::where('thread_id', $threadId)
            ->where('receiver_id', $currentUser->id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        if ($currentUser->role === 'super_admin' || $currentUser->id === $thread->admin_id) {
            $thread->update(['unread_admin_count' => 0]);
        } else {
            $thread->update(['unread_employee_count' => 0]);
        }

        return response()->json([
            'message' => 'Thread marked as read.',
        ]);
    }

    /**
     * Get total unread count for current user.
     */
    public function getUnreadCount(Request $request): JsonResponse
    {
        $currentUser = $request->user();

        if ($currentUser->role === 'super_admin') {
            $count = (int) ChatThread::where('admin_id', $currentUser->id)->sum('unread_admin_count');
        } else {
            $count = (int) ChatThread::where('employee_id', $currentUser->id)->sum('unread_employee_count');
        }

        return response()->json([
            'unread_count' => $count,
        ]);
    }
}
