<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('academic_year_id')->nullable()->after('section_id')->constrained('academic_years')->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->after('academic_year_id')->constrained('shifts')->nullOnDelete();
            $table->foreignId('version_id')->nullable()->after('shift_id')->constrained('versions')->nullOnDelete();
            $table->foreignId('group_trade_id')->nullable()->after('version_id')->constrained('groups_trades')->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('group_trade_id');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropForeign(['academic_year_id']);
            $table->dropForeign(['shift_id']);
            $table->dropForeign(['version_id']);
            $table->dropForeign(['group_trade_id']);
            $table->dropColumn(['academic_year_id', 'shift_id', 'version_id', 'group_trade_id', 'is_active']);
        });
    }
};
