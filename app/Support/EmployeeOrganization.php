<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EmployeeOrganization
{
    public static function resolve(
        ?int $departmentId,
        ?int $designationId,
        ?string $departmentName,
        ?string $designationTitle
    ): array {
        $department = $departmentId
            ? DB::table('departments')->find($departmentId)
            : self::findUniqueDepartment($departmentName);
        $designation = $designationId
            ? DB::table('designations')->find($designationId)
            : null;

        if ($designationId && !$designation) {
            throw ValidationException::withMessages([
                'designation_id' => ['The selected designation no longer exists.'],
            ]);
        }

        if ($designation && $department && (int) $designation->department_id !== (int) $department->id) {
            throw ValidationException::withMessages([
                'designation_id' => ['The selected designation does not belong to the selected department.'],
            ]);
        }

        if ($designation && !$department) {
            $department = DB::table('departments')->find($designation->department_id);
        }

        if (!$designation && $department && $designationTitle) {
            $designation = self::findUniqueDesignation((int) $department->id, $designationTitle);
        }

        return [
            'department_id' => $department?->id,
            'department' => $department?->name ?? $departmentName,
            'designation_id' => $designation?->id,
            'designation' => $designation?->title ?? $designationTitle,
        ];
    }

    private static function findUniqueDepartment(?string $name): ?object
    {
        if (!$name) {
            return null;
        }

        $matches = DB::table('departments')
            ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower(trim($name))])
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private static function findUniqueDesignation(int $departmentId, string $title): ?object
    {
        $matches = DB::table('designations')
            ->where('department_id', $departmentId)
            ->whereRaw('LOWER(TRIM(title)) = ?', [strtolower(trim($title))])
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
