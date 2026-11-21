<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            // Individual Payment tracking (Super Admin only)
            $table->string('payment_mode', 50)->nullable()->after('payment_status'); // 'Bank Transfer', 'UPI', 'Cheque', 'Cash'
            $table->string('payment_reference', 100)->nullable()->after('payment_mode'); // UTR / Cheque / Txn ID
            $table->timestamp('paid_at')->nullable()->after('payment_reference');
            $table->string('paid_by', 100)->nullable()->after('paid_at');

            // Custom Salary / Bonus / Incentives
            $table->decimal('custom_bonus', 14, 2)->default(0)->after('special_allowance');
            $table->string('custom_bonus_label', 150)->nullable()->after('custom_bonus'); // e.g. "Performance Incentive", "Overtime"

            // Detailed Itemized Penalties Breakdown
            $table->decimal('late_penalty_days', 5, 2)->default(0)->after('lop_deduction');
            $table->decimal('late_penalty_amount', 14, 2)->default(0)->after('late_penalty_days');
            $table->string('late_count_summary', 255)->nullable()->after('late_penalty_amount'); // e.g. "3 Late Marks (Half-day penalty triggered)"

            // Custom / Disciplinary Penalties
            $table->decimal('other_penalty_amount', 14, 2)->default(0)->after('late_count_summary');
            $table->string('other_penalty_reason', 255)->nullable()->after('other_penalty_amount');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn([
                'payment_mode',
                'payment_reference',
                'paid_at',
                'paid_by',
                'custom_bonus',
                'custom_bonus_label',
                'late_penalty_days',
                'late_penalty_amount',
                'late_count_summary',
                'other_penalty_amount',
                'other_penalty_reason',
            ]);
        });
    }
};
