<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AuditLog;
use App\Support\RoleAccess;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PerformanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $cycles = DB::table('performance_cycles')
            ->orderByDesc('year')
            ->orderByDesc('quarter')
            ->get();

        $objectiveQuery = DB::table('performance_objectives')
            ->join('performance_cycles', 'performance_cycles.id', '=', 'performance_objectives.cycle_id')
            ->join('employees', 'employees.id', '=', 'performance_objectives.employee_id')
            ->leftJoin('users as reviewers', 'reviewers.id', '=', 'performance_objectives.reviewed_by_user_id')
            ->leftJoin('users as raters', 'raters.id', '=', 'performance_objectives.manager_rated_by_user_id')
            ->orderByDesc('performance_cycles.year')
            ->orderByDesc('performance_cycles.quarter')
            ->orderBy('employees.first_name')
            ->orderBy('performance_objectives.id');

        if ($user->role === 'manager') {
            $objectiveQuery->whereIn(
                'performance_objectives.employee_id',
                RoleAccess::visibleEmployeeIds($user)
            );
        } elseif ($user->role !== 'super_admin') {
            $objectiveQuery->where('performance_objectives.employee_id', $user->employee_id ?? 0);
        }

        $objectives = $objectiveQuery->get([
            'performance_objectives.*',
            'performance_cycles.year',
            'performance_cycles.quarter',
            'performance_cycles.starts_on',
            'performance_cycles.ends_on',
            'performance_cycles.status as cycle_status',
            'employees.employee_code',
            'employees.first_name',
            'employees.last_name',
            'reviewers.name as reviewed_by_name',
            'raters.name as manager_rated_by_name',
        ]);
        $keyResults = DB::table('performance_key_results')
            ->whereIn('objective_id', $objectives->pluck('id'))
            ->orderBy('id')
            ->get()
            ->groupBy('objective_id');

        $objectives->each(function ($objective) use ($keyResults) {
            $objectiveKeyResults = $keyResults->get($objective->id, collect())->values();
            $objective->progress_percent = $objectiveKeyResults->isNotEmpty()
                ? (int) round($objectiveKeyResults->avg('progress_percent'))
                : 0;
            $objective->key_results = $objectiveKeyResults;
        });

        return response()->json([
            'data' => [
                'cycles' => $cycles,
                'objectives' => $objectives,
            ],
        ]);
    }

    public function storeCycle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => 'required|integer|min:2000|max:2100',
            'quarter' => 'required|integer|between:1,4',
        ]);
        if (
            DB::table('performance_cycles')
                ->where('year', $validated['year'])
                ->where('quarter', $validated['quarter'])
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'quarter' => 'A performance cycle already exists for this quarter and year.',
            ]);
        }

        $startMonth = (($validated['quarter'] - 1) * 3) + 1;
        $startDate = Carbon::create($validated['year'], $startMonth, 1)->startOfMonth();
        try {
            $cycleId = DB::table('performance_cycles')->insertGetId([
                'year' => $validated['year'],
                'quarter' => $validated['quarter'],
                'starts_on' => $startDate->toDateString(),
                'ends_on' => $startDate->copy()->addMonths(3)->subDay()->toDateString(),
                'status' => 'open',
                'created_by_user_id' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if (! DB::table('performance_cycles')
                ->where('year', $validated['year'])
                ->where('quarter', $validated['quarter'])
                ->exists()) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'quarter' => 'A performance cycle already exists for this quarter and year.',
            ]);
        }

        AuditLog::record(
            $request,
            'PERFORMANCE_CYCLE_CREATED',
            'Performance',
            "Created Q{$validated['quarter']} {$validated['year']} performance cycle"
        );

        return response()->json(['data' => $this->loadCycle($cycleId)], 201);
    }

    public function closeCycle(Request $request, int $cycleId): JsonResponse
    {
        DB::transaction(function () use ($request, $cycleId) {
            $cycle = DB::table('performance_cycles')->where('id', $cycleId)->lockForUpdate()->first();
            abort_unless($cycle, 404, 'Performance cycle not found.');
            abort_if($cycle->status !== 'open', 409, 'This performance cycle is already closed.');
            abort_if($cycle->ends_on >= Carbon::today()->toDateString(), 409, 'A cycle can only be closed after its end date.');

            $objectives = DB::table('performance_objectives')
                ->where('cycle_id', $cycleId)
                ->get(['id', 'status', 'self_rating', 'manager_rating']);
            abort_if($objectives->isEmpty(), 409, 'A cycle needs objectives before it can be closed.');

            $activeObjectives = $objectives->reject(
                fn ($objective) => in_array($objective->status, ['draft', 'returned'], true)
            );
            abort_if($activeObjectives->isEmpty(), 409, 'A cycle needs submitted objectives before it can be closed.');
            abort_if(
                $activeObjectives->contains(
                    fn ($objective) => $objective->status !== 'approved'
                        || $objective->self_rating === null
                        || $objective->manager_rating === null
                ),
                409,
                'Resolve all objective reviews and ratings before closing the cycle.'
            );

            $abandonedIds = $objectives
                ->filter(fn ($objective) => in_array($objective->status, ['draft', 'returned'], true))
                ->pluck('id');
            if ($abandonedIds->isNotEmpty()) {
                DB::table('performance_key_results')->whereIn('objective_id', $abandonedIds)->delete();
                DB::table('performance_objectives')->whereIn('id', $abandonedIds)->delete();
            }

            DB::table('performance_cycles')->where('id', $cycleId)->update([
                'status' => 'closed',
                'updated_at' => now(),
            ]);
            AuditLog::record(
                $request,
                'PERFORMANCE_CYCLE_CLOSED',
                'Performance',
                "Closed performance cycle Q{$cycle->quarter} {$cycle->year}"
            );
        });

        return response()->json(['data' => $this->loadCycle($cycleId)]);
    }

    public function storeObjective(Request $request, int $cycleId): JsonResponse
    {
        $employeeId = $this->activeEmployeeId($request);
        $validated = $this->validateObjective($request);

        $objectiveId = DB::transaction(function () use ($request, $cycleId, $employeeId, $validated) {
            $cycle = DB::table('performance_cycles')->where('id', $cycleId)->lockForUpdate()->first();
            abort_unless($cycle, 404, 'Performance cycle not found.');
            $this->assertGoalUpdatesAllowed($cycle);
            abort_if(
                DB::table('performance_objectives')
                    ->where('cycle_id', $cycleId)
                    ->where('employee_id', $employeeId)
                    ->whereIn('status', ['submitted', 'approved'])
                    ->exists(),
                409,
                'The objective set has already been submitted; add objectives before the first submission.'
            );

            return $this->insertObjective($request, $cycleId, $employeeId, $validated);
        });

        return response()->json(['data' => $this->loadObjective($objectiveId)], 201);
    }

    public function updateObjective(Request $request, int $objectiveId): JsonResponse
    {
        $employeeId = $this->activeEmployeeId($request);
        $validated = $this->validateObjective($request);

        DB::transaction(function () use ($request, $objectiveId, $employeeId, $validated) {
            $objective = DB::table('performance_objectives')
                ->where('id', $objectiveId)
                ->lockForUpdate()
                ->first();
            abort_unless($objective, 404, 'Objective not found.');
            abort_unless((int) $objective->employee_id === $employeeId, 403);
            $this->assertEditableObjective($objective);

            DB::table('performance_objectives')->where('id', $objectiveId)->update([
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'weight_percent' => $validated['weight_percent'],
                'status' => 'draft',
                'manager_note' => null,
                'reviewed_by_user_id' => null,
                'reviewed_at' => null,
                'updated_at' => now(),
            ]);
            DB::table('performance_key_results')->where('objective_id', $objectiveId)->delete();
            $this->insertKeyResults($objectiveId, $validated['key_results']);
            AuditLog::record($request, 'PERFORMANCE_OBJECTIVE_UPDATED', 'Performance', "Updated objective #{$objectiveId}");
        });

        return response()->json(['data' => $this->loadObjective($objectiveId)]);
    }

    public function destroyObjective(Request $request, int $objectiveId): JsonResponse
    {
        $employeeId = $this->activeEmployeeId($request);
        DB::transaction(function () use ($request, $objectiveId, $employeeId) {
            $objective = DB::table('performance_objectives')
                ->where('id', $objectiveId)
                ->lockForUpdate()
                ->first();
            abort_unless($objective, 404, 'Objective not found.');
            abort_unless((int) $objective->employee_id === $employeeId, 403);
            $this->assertEditableObjective($objective);
            abort_if(
                DB::table('performance_objectives')
                    ->where('cycle_id', $objective->cycle_id)
                    ->where('employee_id', $employeeId)
                    ->whereIn('status', ['submitted', 'approved'])
                    ->exists(),
                409,
                'Objectives cannot be removed after the objective set has been submitted.'
            );

            DB::table('performance_objectives')->where('id', $objectiveId)->delete();
            AuditLog::record($request, 'PERFORMANCE_OBJECTIVE_DELETED', 'Performance', "Deleted draft objective #{$objectiveId}");
        });

        return response()->json(['message' => 'Draft objective deleted.']);
    }

    public function submitCycleObjectives(Request $request, int $cycleId): JsonResponse
    {
        $employeeId = $this->activeEmployeeId($request);
        DB::transaction(function () use ($request, $cycleId, $employeeId) {
            $cycle = DB::table('performance_cycles')->where('id', $cycleId)->lockForUpdate()->first();
            abort_unless($cycle, 404, 'Performance cycle not found.');
            $this->assertGoalUpdatesAllowed($cycle);

            $objectives = DB::table('performance_objectives')
                ->where('cycle_id', $cycleId)
                ->where('employee_id', $employeeId)
                ->lockForUpdate()
                ->get(['id', 'weight_percent', 'status']);
            abort_if($objectives->isEmpty(), 409, 'Add at least one objective before submitting.');

            $weightTotal = round($objectives->sum(fn ($objective) => (float) $objective->weight_percent), 2);
            if ($weightTotal !== 100.0) {
                throw ValidationException::withMessages([
                    'weight_percent' => 'Objective weights must total exactly 100% for the quarter.',
                ]);
            }

            $editableIds = $objectives
                ->whereIn('status', ['draft', 'returned'])
                ->pluck('id')
                ->all();
            abort_if(empty($editableIds), 409, 'There are no draft or returned objectives to submit.');

            DB::table('performance_objectives')
                ->whereIn('id', $editableIds)
                ->update([
                    'status' => 'submitted',
                    'submitted_at' => now(),
                    'manager_note' => null,
                    'reviewed_by_user_id' => null,
                    'reviewed_at' => null,
                    'updated_at' => now(),
                ]);
            AuditLog::record(
                $request,
                'PERFORMANCE_OBJECTIVES_SUBMITTED',
                'Performance',
                'Submitted '.count($editableIds)." objectives for Q{$cycle->quarter} {$cycle->year}"
            );
        });

        return response()->json(['data' => $this->loadCycleObjectives($cycleId, $employeeId)]);
    }

    public function reviewObjective(Request $request, int $objectiveId): JsonResponse
    {
        $validated = $request->validate([
            'decision' => 'required|in:approved,returned',
            'manager_note' => 'nullable|string|max:2000',
        ]);
        if ($validated['decision'] === 'returned' && blank($validated['manager_note'] ?? null)) {
            throw ValidationException::withMessages([
                'manager_note' => 'A note is required when returning an objective.',
            ]);
        }

        DB::transaction(function () use ($request, $objectiveId, $validated) {
            $objective = DB::table('performance_objectives')
                ->where('id', $objectiveId)
                ->lockForUpdate()
                ->first();
            abort_unless($objective, 404, 'Objective not found.');
            $this->assertCanReview($request, (int) $objective->employee_id);
            abort_if($objective->status !== 'submitted', 409, 'Only submitted objectives can be reviewed.');
            abort_if(
                DB::table('performance_cycles')->where('id', $objective->cycle_id)->value('status') !== 'open',
                409,
                'This performance cycle is closed.'
            );

            DB::table('performance_objectives')->where('id', $objectiveId)->update([
                'status' => $validated['decision'],
                'manager_note' => $validated['manager_note'] ?? null,
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);
            AuditLog::record(
                $request,
                'PERFORMANCE_OBJECTIVE_REVIEWED',
                'Performance',
                "Reviewed objective #{$objectiveId}: {$validated['decision']}"
            );
        });

        return response()->json(['data' => $this->loadObjective($objectiveId)]);
    }

    public function updateKeyResultProgress(Request $request, int $keyResultId): JsonResponse
    {
        $validated = $request->validate([
            'progress_percent' => 'required|integer|min:0|max:100',
        ]);
        $employeeId = $this->activeEmployeeId($request);

        $objectiveId = DB::transaction(function () use ($request, $keyResultId, $employeeId, $validated) {
            $keyResult = DB::table('performance_key_results')
                ->where('id', $keyResultId)
                ->lockForUpdate()
                ->first();
            abort_unless($keyResult, 404, 'Key result not found.');
            $objective = DB::table('performance_objectives')
                ->where('id', $keyResult->objective_id)
                ->lockForUpdate()
                ->first();
            abort_unless($objective, 404, 'Objective not found.');
            abort_unless((int) $objective->employee_id === $employeeId, 403);
            abort_if($objective->status !== 'approved', 409, 'Progress can be updated only for approved objectives.');
            $cycle = DB::table('performance_cycles')->where('id', $objective->cycle_id)->first();
            abort_unless($cycle, 404, 'Performance cycle not found.');
            $this->assertGoalUpdatesAllowed($cycle);

            DB::table('performance_key_results')->where('id', $keyResultId)->update([
                'progress_percent' => $validated['progress_percent'],
                'updated_at' => now(),
            ]);
            AuditLog::record(
                $request,
                'PERFORMANCE_KEY_RESULT_UPDATED',
                'Performance',
                "Updated key result #{$keyResultId} progress to {$validated['progress_percent']}%"
            );

            return (int) $keyResult->objective_id;
        });

        return response()->json(['data' => $this->loadObjective($objectiveId)]);
    }

    public function submitSelfReview(Request $request, int $objectiveId): JsonResponse
    {
        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'review_note' => 'nullable|string|max:2000',
        ]);
        $employeeId = $this->activeEmployeeId($request);

        DB::transaction(function () use ($request, $objectiveId, $employeeId, $validated) {
            $objective = DB::table('performance_objectives')
                ->where('id', $objectiveId)
                ->lockForUpdate()
                ->first();
            abort_unless($objective, 404, 'Objective not found.');
            abort_unless((int) $objective->employee_id === $employeeId, 403);
            $this->assertReadyForRating($objective);

            DB::table('performance_objectives')->where('id', $objectiveId)->update([
                'self_rating' => $validated['rating'],
                'self_review_note' => $validated['review_note'] ?? null,
                'self_reviewed_at' => now(),
                'updated_at' => now(),
            ]);
            AuditLog::record($request, 'PERFORMANCE_SELF_REVIEWED', 'Performance', "Submitted self-rating for objective #{$objectiveId}");
        });

        return response()->json(['data' => $this->loadObjective($objectiveId)]);
    }

    public function submitManagerReview(Request $request, int $objectiveId): JsonResponse
    {
        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'review_note' => 'nullable|string|max:2000',
        ]);

        DB::transaction(function () use ($request, $objectiveId, $validated) {
            $objective = DB::table('performance_objectives')
                ->where('id', $objectiveId)
                ->lockForUpdate()
                ->first();
            abort_unless($objective, 404, 'Objective not found.');
            $this->assertCanReview($request, (int) $objective->employee_id);
            $this->assertReadyForRating($objective);
            abort_if($objective->self_rating === null, 409, 'The employee must submit a self-rating first.');

            DB::table('performance_objectives')->where('id', $objectiveId)->update([
                'manager_rating' => $validated['rating'],
                'manager_review_note' => $validated['review_note'] ?? null,
                'manager_rated_by_user_id' => $request->user()->id,
                'manager_rated_at' => now(),
                'updated_at' => now(),
            ]);
            AuditLog::record($request, 'PERFORMANCE_MANAGER_REVIEWED', 'Performance', "Submitted manager rating for objective #{$objectiveId}");
        });

        return response()->json(['data' => $this->loadObjective($objectiveId)]);
    }

    private function validateObjective(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'weight_percent' => 'required|numeric|gt:0|lte:100',
            'key_results' => 'required|array|min:1|max:10',
            'key_results.*.title' => 'required|string|max:255',
        ]);
    }

    private function insertObjective(Request $request, int $cycleId, int $employeeId, array $validated): int
    {
        $now = now();
        $objectiveId = DB::table('performance_objectives')->insertGetId([
            'cycle_id' => $cycleId,
            'employee_id' => $employeeId,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'weight_percent' => $validated['weight_percent'],
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->insertKeyResults($objectiveId, $validated['key_results']);
        AuditLog::record($request, 'PERFORMANCE_OBJECTIVE_CREATED', 'Performance', "Created objective #{$objectiveId}");

        return $objectiveId;
    }

    private function insertKeyResults(int $objectiveId, array $keyResults): void
    {
        $now = now();
        foreach ($keyResults as $keyResult) {
            DB::table('performance_key_results')->insert([
                'objective_id' => $objectiveId,
                'title' => $keyResult['title'],
                'progress_percent' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function assertEditableObjective(object $objective): void
    {
        abort_unless(in_array($objective->status, ['draft', 'returned'], true), 409, 'Only draft or returned objectives can be edited.');
        $cycle = DB::table('performance_cycles')->where('id', $objective->cycle_id)->first();
        abort_unless($cycle, 404, 'Performance cycle not found.');
        $this->assertGoalUpdatesAllowed($cycle);
    }

    private function assertGoalUpdatesAllowed(object $cycle): void
    {
        abort_if($cycle->status !== 'open', 409, 'This performance cycle is closed.');
        $today = Carbon::today()->toDateString();
        abort_if($cycle->starts_on > $today, 409, 'Objective entry is not open yet.');
        abort_if($cycle->ends_on < $today, 409, 'Objective entry has closed for this quarter.');
    }

    private function assertCanReview(Request $request, int $employeeId): void
    {
        $user = $request->user();
        if ($user->role === 'super_admin') {
            return;
        }

        abort_unless(
            $user->role === 'manager'
                && (int) $user->employee_id !== $employeeId
                && RoleAccess::managesEmployee($user, $employeeId),
            403
        );
    }

    private function assertReadyForRating(object $objective): void
    {
        abort_if($objective->status !== 'approved', 409, 'Only approved objectives can be rated.');
        $cycle = DB::table('performance_cycles')->where('id', $objective->cycle_id)->first();
        abort_unless($cycle, 404, 'Performance cycle not found.');
        abort_if($cycle->status !== 'open', 409, 'This performance cycle is closed.');
        abort_if($cycle->ends_on >= Carbon::today()->toDateString(), 409, 'Ratings open after the quarter end date.');
    }

    private function activeEmployeeId(Request $request): int
    {
        $employeeId = (int) ($request->user()->employee_id ?? 0);
        abort_unless(
            $employeeId
                && DB::table('employees')->where('id', $employeeId)->where('status', 'active')->exists(),
            403,
            'This account is not linked to an active employee record.'
        );

        return $employeeId;
    }

    private function loadCycle(int $cycleId): object
    {
        $cycle = DB::table('performance_cycles')->where('id', $cycleId)->first();
        abort_unless($cycle, 404, 'Performance cycle not found.');

        return $cycle;
    }

    private function loadCycleObjectives(int $cycleId, int $employeeId)
    {
        return DB::table('performance_objectives')
            ->where('cycle_id', $cycleId)
            ->where('employee_id', $employeeId)
            ->orderBy('id')
            ->get()
            ->map(fn ($objective) => $this->attachKeyResults($objective));
    }

    private function loadObjective(int $objectiveId): object
    {
        $objective = DB::table('performance_objectives')
            ->join('performance_cycles', 'performance_cycles.id', '=', 'performance_objectives.cycle_id')
            ->join('employees', 'employees.id', '=', 'performance_objectives.employee_id')
            ->leftJoin('users as reviewers', 'reviewers.id', '=', 'performance_objectives.reviewed_by_user_id')
            ->leftJoin('users as raters', 'raters.id', '=', 'performance_objectives.manager_rated_by_user_id')
            ->where('performance_objectives.id', $objectiveId)
            ->first([
                'performance_objectives.*',
                'performance_cycles.year',
                'performance_cycles.quarter',
                'performance_cycles.starts_on',
                'performance_cycles.ends_on',
                'performance_cycles.status as cycle_status',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                'reviewers.name as reviewed_by_name',
                'raters.name as manager_rated_by_name',
            ]);
        abort_unless($objective, 404, 'Objective not found.');

        return $this->attachKeyResults($objective);
    }

    private function attachKeyResults(object $objective): object
    {
        $objective->key_results = DB::table('performance_key_results')
            ->where('objective_id', $objective->id)
            ->orderBy('id')
            ->get();
        $objective->progress_percent = $objective->key_results->isNotEmpty()
            ? (int) round($objective->key_results->avg('progress_percent'))
            : 0;

        return $objective;
    }
}
