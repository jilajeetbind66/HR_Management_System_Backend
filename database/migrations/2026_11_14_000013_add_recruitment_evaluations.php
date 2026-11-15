<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_candidates', function (Blueprint $table) {
            $table->text('evaluation_note')->nullable()->after('rating');
            $table->foreignId('evaluated_by_user_id')->nullable()->after('evaluation_note')->constrained('users')->nullOnDelete();
            $table->timestamp('evaluated_at')->nullable()->after('evaluated_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_candidates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('evaluated_by_user_id');
            $table->dropColumn(['evaluation_note', 'evaluated_at']);
        });
    }
};
