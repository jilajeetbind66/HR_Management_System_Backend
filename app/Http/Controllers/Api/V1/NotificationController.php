<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $query = DB::table('user_notifications')->where('user_id', $userId);

        return response()->json([
            'data' => $query->orderByDesc('id')->limit(25)->get(),
            'unread_count' => DB::table('user_notifications')
                ->where('user_id', $userId)
                ->whereNull('read_at')
                ->count(),
        ]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $notification = DB::table('user_notifications')
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();
        abort_unless($notification, 404);

        if (!$notification->read_at) {
            DB::table('user_notifications')
                ->where('id', $id)
                ->where('user_id', $request->user()->id)
                ->update(['read_at' => now(), 'updated_at' => now()]);
        }

        return response()->json([
            'data' => DB::table('user_notifications')
                ->where('id', $id)
                ->where('user_id', $request->user()->id)
                ->first(),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        DB::table('user_notifications')
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        return response()->json([
            'message' => 'All notifications marked as read',
            'unread_count' => 0,
        ]);
    }

    public function clearAll(Request $request): JsonResponse
    {
        DB::table('user_notifications')
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json([
            'message' => 'All notifications cleared',
            'unread_count' => 0,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $deleted = DB::table('user_notifications')
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->delete();
        abort_unless($deleted, 404, 'Notification not found.');

        return response()->json([
            'message' => 'Notification deleted',
            'unread_count' => DB::table('user_notifications')
                ->where('user_id', $request->user()->id)
                ->whereNull('read_at')
                ->count(),
        ]);
    }
}
