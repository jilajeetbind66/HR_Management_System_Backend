<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class TrainingNotification
{
    public static function sendOnce(
        int $enrollmentId,
        int $recipientUserId,
        string $dispatchKey,
        string $notificationKey,
        string $title,
        string $message
    ): void {
        DB::transaction(function () use (
            $enrollmentId,
            $recipientUserId,
            $dispatchKey,
            $notificationKey,
            $title,
            $message
        ): void {
            $now = now();
            $inserted = DB::table('training_notification_dispatches')->insertOrIgnore([
                'enrollment_id' => $enrollmentId,
                'recipient_user_id' => $recipientUserId,
                'event_key' => $dispatchKey,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($inserted !== 1) {
                return;
            }

            DB::table('user_notifications')->insert([
                'user_id' => $recipientUserId,
                'event_key' => $notificationKey,
                'title' => $title,
                'message' => $message,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }
}
