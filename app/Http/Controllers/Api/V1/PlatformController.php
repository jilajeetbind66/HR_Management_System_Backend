<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PlatformController extends Controller
{
    public function auditLogs(): JsonResponse
    {
        $logs = DB::table('audit_logs')->orderBy('id', 'desc')->take(50)->get();
        return response()->json(['data' => $logs]);
    }

    public function schedulerRuns(): JsonResponse
    {
        $runs = DB::table('scheduler_runs')->orderBy('id', 'desc')->take(20)->get();
        return response()->json(['data' => $runs]);
    }
}
