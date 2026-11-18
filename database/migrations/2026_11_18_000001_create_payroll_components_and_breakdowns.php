<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_components', function (Blueprint $table) {
            $table->id();
            $table->string('component_key', 60)->unique();
            $table->string('name', 100);
            $table->string('component_group', 20);
            $table->string('calculation_type', 30);
            $table->decimal('value', 12, 4)->default(0);
            $table->decimal('metro_value', 12, 4)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        DB::table('payroll_components')->insert([
            [
                'component_key' => 'basic',
                'name' => 'Basic Salary',
                'component_group' => 'earning',
                'calculation_type' => 'employee_value',
                'value' => 0,
                'metro_value' => null,
                'is_enabled' => true,
                'sort_order' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'component_key' => 'hra',
                'name' => 'House Rent Allowance (HRA)',
                'component_group' => 'earning',
                'calculation_type' => 'percent_basic',
                'value' => 40,
                'metro_value' => 50,
                'is_enabled' => true,
                'sort_order' => 20,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'component_key' => 'conveyance',
                'name' => 'Conveyance',
                'component_group' => 'earning',
                'calculation_type' => 'fixed',
                'value' => 0,
                'metro_value' => null,
                'is_enabled' => false,
                'sort_order' => 30,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'component_key' => 'medical',
                'name' => 'Medical Allowance',
                'component_group' => 'earning',
                'calculation_type' => 'fixed',
                'value' => 0,
                'metro_value' => null,
                'is_enabled' => false,
                'sort_order' => 40,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'component_key' => 'special_allowance',
                'name' => 'Special Allowance',
                'component_group' => 'earning',
                'calculation_type' => 'residual',
                'value' => 0,
                'metro_value' => null,
                'is_enabled' => true,
                'sort_order' => 50,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'component_key' => 'bonus',
                'name' => 'Bonus',
                'component_group' => 'earning',
                'calculation_type' => 'fixed',
                'value' => 0,
                'metro_value' => null,
                'is_enabled' => false,
                'sort_order' => 60,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'component_key' => 'gratuity',
                'name' => 'Gratuity',
                'component_group' => 'employer_cost',
                'calculation_type' => 'percent_basic',
                'value' => 0,
                'metro_value' => null,
                'is_enabled' => false,
                'sort_order' => 70,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        Schema::table('payslips', function (Blueprint $table) {
            $table->json('component_breakdown')->nullable();
            $table->decimal('employer_cost_total', 14, 2)->default(0);
            $table->decimal('total_ctc_monthly', 14, 2)->default(0);
            $table->decimal('ctc_annual', 14, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn([
                'component_breakdown',
                'employer_cost_total',
                'total_ctc_monthly',
                'ctc_annual',
            ]);
        });

        Schema::dropIfExists('payroll_components');
    }
};
