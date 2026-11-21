<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlatformController extends Controller
{
    public function auditLogs(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 100), 1), 250);
        $module = trim((string) $request->query('module', ''));
        $search = trim((string) $request->query('search', ''));

        $query = DB::table('audit_logs');
        if ($module !== '') {
            $query->where('module', $module);
        }
        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where(function ($sub) use ($like) {
                $sub->where('action', 'like', $like)
                    ->orWhere('actor', 'like', $like)
                    ->orWhere('module', 'like', $like)
                    ->orWhere('description', 'like', $like);
            });
        }

        $total = (clone $query)->count();
        $logs = $query->orderBy('id', 'desc')->take($limit)->get();
        $modules = DB::table('audit_logs')
            ->select('module')
            ->distinct()
            ->orderBy('module')
            ->pluck('module')
            ->values();

        return response()->json([
            'data' => $logs,
            'meta' => [
                'total' => $total,
                'returned' => $logs->count(),
                'limit' => $limit,
                'modules' => $modules,
            ],
        ]);
    }

    public function schedulerRuns(): JsonResponse
    {
        $total = DB::table('scheduler_runs')->count();
        $runs = DB::table('scheduler_runs')->orderBy('id', 'desc')->take(50)->get();

        return response()->json([
            'data' => $runs,
            'meta' => [
                'total' => $total,
                'scheduled_jobs' => [
                    [
                        'command' => 'hrms:training-notifications',
                        'schedule' => 'Daily at 08:00',
                        'description' => 'Sends mandatory training due reminders and manager overdue alerts.',
                    ],
                ],
            ],
        ]);
    }

    public function sequences(): JsonResponse
    {
        $definitions = [
            ['key' => 'employees', 'label' => 'Employee Codes', 'prefix' => 'EMP-', 'table' => 'employees', 'column' => 'employee_code'],
            ['key' => 'projects', 'label' => 'Project Codes', 'prefix' => 'PRJ-' . now()->format('Y') . '-', 'table' => 'projects', 'column' => 'project_code'],
            ['key' => 'assets', 'label' => 'Asset Codes', 'prefix' => 'AST-', 'table' => 'company_assets', 'column' => 'asset_code'],
            ['key' => 'helpdesk', 'label' => 'Helpdesk Tickets', 'prefix' => 'TKT-', 'table' => 'helpdesk_tickets', 'column' => 'ticket_code'],
            ['key' => 'hr_letters', 'label' => 'HR Letters', 'prefix' => 'LTR-', 'table' => 'hr_letters', 'column' => 'letter_number'],
            ['key' => 'training', 'label' => 'Training Certificates', 'prefix' => 'TRN-', 'table' => 'training_enrollments', 'column' => 'certificate_code'],
        ];

        $sequences = array_map(static function (array $def): array {
            $query = DB::table($def['table'])->whereNotNull($def['column'])->where($def['column'], '<>', '');
            $count = (clone $query)->count();
            $latest = $query->orderByDesc('id')->value($def['column']);

            return [
                'key' => $def['key'],
                'label' => $def['label'],
                'prefix' => $def['prefix'],
                'total_issued' => $count,
                'latest_code' => $latest,
            ];
        }, $definitions);

        return response()->json(['data' => $sequences]);
    }
}
