<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_extension_requests', function (Blueprint $table) {
            $table->string('on_behalf_of')->nullable()->after('requested_by');
        });
    }

    public function down(): void
    {
        Schema::table('project_extension_requests', function (Blueprint $table) {
            $table->dropColumn('on_behalf_of');
        });
    }
};
