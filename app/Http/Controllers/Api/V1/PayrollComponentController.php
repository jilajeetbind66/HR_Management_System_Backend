<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PayrollComponentController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => DB::table('payroll_components')->orderBy('sort_order')->orderBy('id')->get(),
            'meta' => [
                'salary_configuration' => [
                    'configured_count' => DB::table('employees')
                        ->join('employee_statutory_details', 'employees.id', '=', 'employee_statutory_details.employee_id')
                        ->whereIn('employees.status', ['active', 'on_notice'])
                        ->where('employees.is_demo', false)
                        ->where('employee_statutory_details.gross_monthly', '>', 0)
                        ->where('employee_statutory_details.basic_monthly', '>', 0)
                        ->whereColumn('employee_statutory_details.basic_monthly', '<=', 'employee_statutory_details.gross_monthly')
                        ->count(),
                    'total_count' => DB::table('employees')
                        ->whereIn('status', ['active', 'on_notice'])
                        ->where('is_demo', false)
                        ->count(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateComponent($request);
        $key = Str::slug($validated['name'], '_');
        if ($key === '' || DB::table('payroll_components')->where('component_key', $key)->exists()) {
            throw ValidationException::withMessages([
                'name' => 'Enter a component name that produces a unique valid key.',
            ]);
        }

        $this->validateRequiredComponentRules($validated, $key);
        $now = now();
        $id = DB::table('payroll_components')->insertGetId([
            ...$validated,
            'component_key' => $key,
            'is_enabled' => $validated['is_enabled'] ?? true,
            'sort_order' => DB::table('payroll_components')->max('sort_order') + 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        AuditLog::record(
            $request,
            'PAYROLL_COMPONENT_CREATED',
            'Payroll',
            "Created payroll component {$key}."
        );

        return response()->json([
            'data' => DB::table('payroll_components')->where('id', $id)->first(),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $component = DB::table('payroll_components')->where('id', $id)->first();
        abort_unless($component, 404, 'Payroll component not found.');

        $validated = $this->validateComponent($request, true);
        $validated['is_enabled'] = $validated['is_enabled'] ?? (bool) $component->is_enabled;
        $this->validateRequiredComponentRules(
            array_merge((array) $component, $validated),
            $component->component_key
        );

        DB::transaction(function () use ($request, $component, $validated): void {
            DB::table('payroll_components')->where('id', $component->id)->update([
                ...$validated,
                'updated_at' => now(),
            ]);
            $changedFields = array_keys($validated);
            AuditLog::record(
                $request,
                'PAYROLL_COMPONENT_UPDATED',
                'Payroll',
                "Updated payroll component {$component->component_key}: " . implode(', ', $changedFields)
            );
        });

        return response()->json([
            'data' => DB::table('payroll_components')->where('id', $id)->first(),
        ]);
    }

    private function validateComponent(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:100'],
            'component_group' => [$required, 'string', 'in:earning,employer_cost'],
            'calculation_type' => [$required, 'string', 'in:employee_value,fixed,percent_basic,percent_gross,residual'],
            'value' => [$required, 'numeric', 'min:0', 'max:99999999'],
            'metro_value' => 'nullable|numeric|min:0|max:100',
            'is_enabled' => 'sometimes|boolean',
        ]);
    }

    private function validateRequiredComponentRules(array $component, string $key): void
    {
        $type = $component['calculation_type'] ?? null;
        $group = $component['component_group'] ?? null;
        $enabled = (bool) ($component['is_enabled'] ?? true);

        if ($key === 'basic' && ($type !== 'employee_value' || $group !== 'earning' || !$enabled)) {
            throw ValidationException::withMessages([
                'calculation_type' => 'Basic Salary must use the employee-specific stored value and remain enabled.',
            ]);
        }
        if ($key === 'special_allowance' && ($type !== 'residual' || $group !== 'earning' || !$enabled)) {
            throw ValidationException::withMessages([
                'calculation_type' => 'Special Allowance must remain enabled as the gross salary residual.',
            ]);
        }
        if ($type === 'residual' && $group !== 'earning') {
            throw ValidationException::withMessages([
                'calculation_type' => 'Residual calculation is only available for earnings components.',
            ]);
        }
        if ($type === 'employee_value' && $key !== 'basic') {
            throw ValidationException::withMessages([
                'calculation_type' => 'Employee-specific stored values are reserved for Basic Salary.',
            ]);
        }
        if (in_array($type, ['percent_basic', 'percent_gross'], true)
            && ((float) ($component['value'] ?? 0) > 100 || (float) ($component['metro_value'] ?? 0) > 100)) {
            throw ValidationException::withMessages([
                'value' => 'Percentage component rates cannot exceed 100%.',
            ]);
        }
        if (($component['metro_value'] ?? null) !== null && $key !== 'hra') {
            throw ValidationException::withMessages([
                'metro_value' => 'Metro-specific rates are only supported for HRA.',
            ]);
        }
    }
}
