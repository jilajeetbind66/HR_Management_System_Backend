<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class PayrollComponentCalculator
{
    private const METRO_CITIES = ['delhi', 'mumbai', 'kolkata', 'chennai'];

    public static function calculate(
        iterable $components,
        float $gross,
        float $basic,
        string $city,
        bool $pfApplicable,
        bool $esiApplicable,
        float $annualCtc,
        string $employeeCode,
        array $statutoryConfig = []
    ): array {
        $enabled = collect($components)->where('is_enabled', true)->values();
        $earnings = [];
        $employerCosts = [];
        $earningsTotal = 0.0;
        $hra = 0.0;
        $specialAllowance = 0.0;

        foreach ($enabled->where('component_group', 'earning') as $component) {
            $type = $component->calculation_type;
            if ($type === 'residual') {
                continue;
            }

            if ($component->component_key === 'basic' || $type === 'employee_value') {
                $amount = $basic;
            } else {
                $rate = self::rateFor($component, $city);
                $amount = self::amountFor($type, $rate, $gross, $basic);
            }

            $amount = round($amount, 2);
            $earningsTotal += $amount;
            if ($component->component_key === 'hra') {
                $hra = $amount;
            }
            $earnings[] = [
                'component_key' => $component->component_key,
                'name' => $component->name,
                'amount' => $amount,
            ];
        }

        if ($earningsTotal > $gross) {
            throw ValidationException::withMessages([
                'components' => ["Enabled earnings components exceed gross salary for {$employeeCode}."],
            ]);
        }

        $residual = $enabled->firstWhere('calculation_type', 'residual');
        if ($residual) {
            $specialAllowance = round($gross - $earningsTotal, 2);
            $earnings[] = [
                'component_key' => $residual->component_key,
                'name' => $residual->name,
                'amount' => $specialAllowance,
            ];
        }

        if ($pfApplicable) {
            $pfBase = self::pfWageBase($basic, $statutoryConfig);
            $employerPf = round($pfBase * (float) ($statutoryConfig['pf_employer_rate'] ?? 12) / 100, 2);
            $employerEps = min(
                round($pfBase * (float) ($statutoryConfig['eps_rate'] ?? 8.33) / 100, 2),
                $employerPf
            );
            $employerCosts[] = [
                'component_key' => 'employer_eps',
                'name' => 'Employer Pension Scheme',
                'amount' => $employerEps,
            ];
            $employerCosts[] = [
                'component_key' => 'employer_pf',
                'name' => 'Employer Provident Fund',
                'amount' => round($employerPf - $employerEps, 2),
            ];
        }
        if ($esiApplicable && $gross <= (float) ($statutoryConfig['esi_gross_limit'] ?? 21000)) {
            $employerCosts[] = [
                'component_key' => 'employer_esi',
                'name' => 'Employer State Insurance',
                'amount' => round($gross * (float) ($statutoryConfig['esi_employer_rate'] ?? 3.25) / 100, 2),
            ];
        }
        foreach ($enabled->where('component_group', 'employer_cost') as $component) {
            $employerCosts[] = [
                'component_key' => $component->component_key,
                'name' => $component->name,
                'amount' => round(self::amountFor(
                    $component->calculation_type,
                    $component->value,
                    $gross,
                    $basic
                ), 2),
            ];
        }

        $employerCostTotal = round(array_sum(array_column($employerCosts, 'amount')), 2);

        return [
            'earnings' => $earnings,
            'employer_costs' => $employerCosts,
            'basic_salary' => $basic,
            'hra' => $hra,
            'special_allowance' => $specialAllowance,
            'employer_cost_total' => $employerCostTotal,
            'total_ctc_monthly' => round($gross + $employerCostTotal, 2),
            'ctc_annual' => $annualCtc,
        ];
    }

    private static function rateFor(object $component, string $city): float
    {
        $isMetro = in_array(mb_strtolower(trim($city)), self::METRO_CITIES, true);

        return (float) ($isMetro && $component->metro_value !== null
            ? $component->metro_value
            : $component->value);
    }

    private static function amountFor(string $type, float $value, float $gross, float $basic): float
    {
        return match ($type) {
            'fixed' => $value,
            'percent_basic' => $basic * $value / 100,
            'percent_gross' => $gross * $value / 100,
            default => 0,
        };
    }

    private static function pfWageBase(float $basic, array $statutoryConfig): float
    {
        if (!($statutoryConfig['apply_pf_wage_ceiling'] ?? true)) {
            return $basic;
        }

        return min($basic, (float) ($statutoryConfig['pf_wage_ceiling'] ?? 15000));
    }
}
