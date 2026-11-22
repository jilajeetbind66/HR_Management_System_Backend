<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AnnouncementsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $announcements = $this->visibleAnnouncementQuery($request)
            ->leftJoin('announcement_reads', function ($join) use ($request) {
                $join->on('announcement_reads.announcement_id', '=', 'announcements.id')
                    ->where('announcement_reads.user_id', '=', $request->user()->id);
            })
            ->select([
                'announcements.*',
                'announcements.created_at as published_at',
                'announcement_reads.read_at',
            ])
            ->orderByDesc('announcements.id')
            ->get()
            ->map(function ($announcement) {
                $announcement->is_read = $announcement->read_at !== null;

                return $announcement;
            });

        return response()->json(['data' => $announcements]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string|max:20000',
            'category' => 'required|in:General,Celebration,Policy,Wellness',
            'target' => 'required|string|max:255',
        ]);
        if (
            $validated['target'] !== 'All Company'
            && ! DB::table('branches')->where('name', $validated['target'])->exists()
        ) {
            throw ValidationException::withMessages([
                'target' => 'Select an existing branch or All Company.',
            ]);
        }

        $announcementId = DB::transaction(function () use ($validated, $request) {
            $now = now();
            $id = DB::table('announcements')->insertGetId([
                ...$validated,
                'published_by' => $request->user()->name,
                'read_count' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Mark the publisher as read immediately
            DB::table('announcement_reads')->insert([
                'announcement_id' => $id,
                'user_id' => $request->user()->id,
                'read_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            AuditLog::record(
                $request,
                'ANNOUNCEMENT_PUBLISHED',
                'Announcements',
                "Published announcement: {$validated['title']}"
            );

            // Send notification to all audience members
            $usersQuery = DB::table('users')->where('id', '!=', $request->user()->id);
            if ($validated['target'] !== 'All Company') {
                $branchId = DB::table('branches')->where('name', $validated['target'])->value('id');
                if ($branchId) {
                    $empIds = DB::table('employees')->where('branch_id', $branchId)->pluck('id');
                    $usersQuery->whereIn('employee_id', $empIds);
                }
            }
            $targetUserIds = $usersQuery->pluck('id');
            $notifications = [];
            foreach ($targetUserIds as $uId) {
                $notifications[] = [
                    'user_id' => $uId,
                    'event_key' => 'announcement.published',
                    'title' => 'New Company Announcement',
                    'message' => "{$request->user()->name}: {$validated['title']}",
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if (!empty($notifications)) {
                DB::table('user_notifications')->insert($notifications);
            }

            return $id;
        });

        return response()->json(['data' => $this->loadVisibleAnnouncement($request, $announcementId)], 201);
    }

    public function markRead(Request $request, int $announcementId): JsonResponse
    {
        DB::transaction(function () use ($request, $announcementId) {
            $announcement = $this->visibleAnnouncementQuery($request)
                ->where('announcements.id', $announcementId)
                ->lockForUpdate()
                ->first(['announcements.id']);
            abort_unless($announcement, 404, 'Announcement not found.');

            $inserted = DB::table('announcement_reads')->insertOrIgnore([
                'announcement_id' => $announcementId,
                'user_id' => $request->user()->id,
                'read_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($inserted > 0) {
                DB::table('announcements')->where('id', $announcementId)->increment('read_count');
                AuditLog::record(
                    $request,
                    'ANNOUNCEMENT_READ',
                    'Announcements',
                    "Marked announcement #{$announcementId} as read"
                );
            }
        });

        return response()->json(['data' => $this->loadVisibleAnnouncement($request, $announcementId)]);
    }

    private function visibleAnnouncementQuery(Request $request)
    {
        $user = $request->user();
        if (in_array($user->role, ['super_admin', 'hr_admin'], true)) {
            return DB::table('announcements');
        }

        $branchId = $user->employee_id
            ? DB::table('employees')->where('id', $user->employee_id)->value('branch_id')
            : null;
        $branchName = $branchId
            ? DB::table('branches')->where('id', $branchId)->value('name')
            : null;
        $query = DB::table('announcements');

        if ($branchName) {
            $query->where(function ($builder) use ($branchName) {
                $builder->where('announcements.target', 'All Company')
                    ->orWhere('announcements.target', $branchName);
            });
        } else {
            $query->where('announcements.target', 'All Company');
        }

        return $query;
    }

    private function loadVisibleAnnouncement(Request $request, int $announcementId): object
    {
        $announcement = $this->visibleAnnouncementQuery($request)
            ->leftJoin('announcement_reads', function ($join) use ($request) {
                $join->on('announcement_reads.announcement_id', '=', 'announcements.id')
                    ->where('announcement_reads.user_id', '=', $request->user()->id);
            })
            ->where('announcements.id', $announcementId)
            ->first([
                'announcements.*',
                'announcements.created_at as published_at',
                'announcement_reads.read_at',
            ]);
        abort_unless($announcement, 404, 'Announcement not found.');
        $announcement->is_read = $announcement->read_at !== null;

        return $announcement;
    }
}
