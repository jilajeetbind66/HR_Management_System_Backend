<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\EmployeeAccountProvisioner;
use App\Support\EmployeeAccountSetupNotifier;
use App\Support\EmployeeConstants;
use App\Support\EmployeeOrganization;
use App\Support\ProbationStatus;
use App\Support\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationalRecordController extends Controller
{
    public function recruitment(): JsonResponse
    {
        $jobs = DB::table('recruitment_jobs')
            ->leftJoin('branches', 'recruitment_jobs.branch_id', '=', 'branches.id')
            ->select('recruitment_jobs.*', 'branches.name as branch')
            ->selectSub(
                DB::table('recruitment_candidates')
                    ->selectRaw('count(*)')
                    ->whereColumn('recruitment_candidates.job_id', 'recruitment_jobs.id'),
                'applicants_count'
            )
            ->orderByDesc('recruitment_jobs.id')
            ->get();

        $candidates = DB::table('recruitment_candidates')
            ->leftJoin('recruitment_jobs', 'recruitment_candidates.job_id', '=', 'recruitment_jobs.id')
            ->leftJoin('users as evaluators', 'evaluators.id', '=', 'recruitment_candidates.evaluated_by_user_id')
            ->select(
                'recruitment_candidates.*',
                'recruitment_jobs.title as job_title',
                'recruitment_jobs.department',
                'recruitment_jobs.branch_id',
                'evaluators.name as evaluated_by_name'
            )
            ->orderByDesc('recruitment_candidates.id')
            ->get()
            ->map(function ($candidate) {
                $candidate->name = trim($candidate->first_name.' '.$candidate->last_name);

                return $candidate;
            });

        return response()->json(['data' => ['jobs' => $jobs, 'candidates' => $candidates]]);
    }

    public function createRecruitmentJob(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'department' => 'required|string|max:255',
            'branch_id' => 'required|exists:branches,id',
            'vacancies' => 'required|integer|min:1',
            'experience' => 'nullable|string|max:100',
        ]);

        $id = DB::transaction(function () use ($validated, $request) {
            $jobId = DB::table('recruitment_jobs')->insertGetId([
                ...$validated,
                'status' => 'Open',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            AuditLog::record($request, 'RECRUITMENT_JOB_CREATED', 'Recruitment', "Created opening {$validated['title']}");

            return $jobId;
        });

        return response()->json(['data' => DB::table('recruitment_jobs')->find($id)], 201);
    }

    public function updateRecruitmentJobStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:Open,Closed',
        ]);

        DB::transaction(function () use ($request, $id, $validated) {
            $job = DB::table('recruitment_jobs')->where('id', $id)->lockForUpdate()->first();
            abort_unless($job, 404, 'Job opening not found.');
            if ($job->status !== $validated['status']) {
                DB::table('recruitment_jobs')->where('id', $id)->update([
                    'status' => $validated['status'],
                    'updated_at' => now(),
                ]);
                AuditLog::record(
                    $request,
                    'RECRUITMENT_JOB_STATUS_UPDATED',
                    'Recruitment',
                    "Updated job opening #{$id} status to {$validated['status']}"
                );
            }
        });

        return response()->json(['data' => DB::table('recruitment_jobs')->find($id)]);
    }

    public function createCandidate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'job_id' => 'required|exists:recruitment_jobs,id',
            'first_name' => 'required|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => ['nullable', 'digits:10'],
            'current_ctc' => 'nullable|string|max:100',
            'expected_ctc' => 'nullable|string|max:100',
            'notice_period' => 'nullable|string|max:100',
        ]);

        $id = DB::transaction(function () use ($validated, $request) {
            $job = DB::table('recruitment_jobs')
                ->where('id', $validated['job_id'])
                ->lockForUpdate()
                ->first();
            abort_unless($job, 404, 'Job opening not found.');
            abort_if($job->status !== 'Open', 409, 'Candidates cannot be added to a closed job opening.');

            $candidateId = DB::table('recruitment_candidates')->insertGetId([
                ...$validated,
                'last_name' => trim((string) ($validated['last_name'] ?? '')),
                'stage' => 'Applied',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            AuditLog::record($request, 'CANDIDATE_CREATED', 'Recruitment', "Added candidate {$validated['email']}");

            return $candidateId;
        });

        return response()->json([
            'data' => DB::table('recruitment_candidates')->where('id', $id)->first(),
        ], 201);
    }

    public function updateCandidateStage(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'stage' => 'required|in:Applied,Screening,Interview,Offered,Rejected',
        ]);

        DB::transaction(function () use ($id, $validated, $request) {
            $candidate = DB::table('recruitment_candidates')->where('id', $id)->lockForUpdate()->first();
            abort_unless($candidate, 404, 'Candidate not found.');
            abort_if($candidate->employee_id, 409, 'Converted candidate stage cannot be changed.');
            DB::table('recruitment_candidates')->where('id', $id)->update([
                'stage' => $validated['stage'],
                'updated_at' => now(),
            ]);
            AuditLog::record(
                $request,
                'CANDIDATE_STAGE_UPDATED',
                'Recruitment',
                "Moved candidate {$candidate->email} to {$validated['stage']}"
            );
        });

        return response()->json(['message' => 'Candidate stage updated']);
    }

    public function evaluateCandidate(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'rating' => 'required|numeric|min:1|max:5',
            'evaluation_note' => 'nullable|string|max:2000',
        ]);

        DB::transaction(function () use ($request, $id, $validated) {
            $candidate = DB::table('recruitment_candidates')->where('id', $id)->lockForUpdate()->first();
            abort_unless($candidate, 404, 'Candidate not found.');
            abort_if($candidate->employee_id, 409, 'Converted candidates cannot be re-evaluated.');
            abort_unless(
                in_array($candidate->stage, ['Interview', 'Offered'], true),
                409,
                'Candidates can be evaluated during Interview or Offered stages.'
            );

            DB::table('recruitment_candidates')->where('id', $id)->update([
                'rating' => $validated['rating'],
                'evaluation_note' => $validated['evaluation_note'] ?? null,
                'evaluated_by_user_id' => $request->user()->id,
                'evaluated_at' => now(),
                'updated_at' => now(),
            ]);
            AuditLog::record(
                $request,
                'CANDIDATE_EVALUATED',
                'Recruitment',
                "Evaluated candidate #{$id} with rating {$validated['rating']}"
            );
        });

        return response()->json([
            'data' => DB::table('recruitment_candidates')->where('id', $id)->first(),
        ]);
    }

    public function convertCandidate(Request $request, int $id): JsonResponse
    {
        $employee = DB::transaction(function () use ($id, $request) {
            $candidate = DB::table('recruitment_candidates')->where('id', $id)->lockForUpdate()->first();
            abort_unless($candidate, 404, 'Candidate not found.');
            abort_if($candidate->employee_id, 409, 'Candidate has already been converted to an employee.');
            abort_unless($candidate->stage === 'Offered', 409, 'Only candidates with an offer can be converted.');

            $job = DB::table('recruitment_jobs')->where('id', $candidate->job_id)->lockForUpdate()->first();
            if (! $job?->branch_id) {
                throw ValidationException::withMessages([
                    'job_id' => 'Assign a branch to this job before converting its candidate.',
                ]);
            }

            if (DB::table('employees')->where('email', $candidate->email)->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'An employee with this email address already exists.',
                ]);
            }

            $nextNumber = (int) DB::table('employees')->max('id') + 1;
            do {
                $employeeCode = 'EMP-'.str_pad((string) $nextNumber++, 3, '0', STR_PAD_LEFT);
            } while (DB::table('employees')->where('employee_code', $employeeCode)->exists());

                $organization = EmployeeOrganization::resolve(
                    null,
                    null,
                    $job->department,
                    $job->title
                );
            $employeeId = DB::table('employees')->insertGetId([
                'employee_code' => $employeeCode,
                'first_name' => $candidate->first_name,
                'last_name' => trim((string) ($candidate->last_name ?? '')),
                'email' => $candidate->email,
                'phone' => $candidate->phone,
                'gender' => EmployeeConstants::GENDER_OTHER,
                'designation' => $organization['designation'],
                'designation_id' => $organization['designation_id'],
                'department' => $organization['department'],
                'department_id' => $organization['department_id'],
                'branch_id' => $job->branch_id,
                'date_of_joining' => now()->toDateString(),
                'employment_type' => 'Unspecified',
                'probation_status' => ProbationStatus::fromJoiningDate(now()->toDateString()),
                'status' => 'active',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('employee_statutory_details')->insert([
                'employee_id' => $employeeId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('leave_balances')->insert([
                'employee_id' => $employeeId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Auto-provision login account for converted employee as documented in architecture guide
            $provisioner = new EmployeeAccountProvisioner();
            $provisioner->provision(
                $employeeId,
                trim($candidate->first_name . ' ' . ($candidate->last_name ?? '')),
                $candidate->email,
                'employee'
            );

            $createdUser = User::where('employee_id', $employeeId)->first();
            if ($createdUser && config('mail.default') !== 'log') {
                try {
                    $notifier = new EmployeeAccountSetupNotifier();
                    $notifier->send($createdUser, $employeeCode, 'employee');
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            DB::table('recruitment_candidates')->where('id', $candidate->id)->update([
                'employee_id' => $employeeId,
                'stage' => 'Hired',
                'updated_at' => now(),
            ]);

            AuditLog::record(
                $request,
                'CANDIDATE_CONVERTED',
                'Recruitment',
                "Converted candidate {$candidate->email} to employee {$employeeCode}"
            );

            return DB::table('employees')
                ->leftJoin('branches', 'employees.branch_id', '=', 'branches.id')
                ->select('employees.id', 'employees.employee_code', 'employees.first_name', 'employees.last_name', 'employees.email', 'employees.phone', 'employees.designation', 'employees.department', 'employees.branch_id', 'branches.name as branch_name', 'employees.date_of_joining', 'employees.status')
                ->where('employees.id', $employeeId)
                ->first();
        });

        return response()->json(['data' => $employee], 201);
    }
}
