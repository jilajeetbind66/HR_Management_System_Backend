<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AuditLog
{
    public static function record(Request $request, string $action, string $module, string $description): void
    {
        DB::table('audit_logs')->insert([
            'action' => $action,
            'actor' => $request->user()->name,
            'module' => $module,
            'description' => $description,
            'ip' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
