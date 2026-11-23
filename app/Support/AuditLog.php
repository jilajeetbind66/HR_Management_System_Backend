<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AuditLog
{
    public static function record(Request $request, string $action, string $module, string $description, ?string $actor = null): void
    {
        $actorName = $actor ?? $request->user()?->name ?? 'System';

        DB::table('audit_logs')->insert([
            'action' => $action,
            'actor' => $actorName,
            'module' => $module,
            'description' => $description,
            'ip' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
