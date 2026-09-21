<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('routines', function (Blueprint $table) { $table->foreignId('academic_year_id')->nullable()->after('school_id')->constrained('academic_years')->nullOnDelete(); $table->foreignId('shift_id')->nullable()->after('academic_year_id')->constrained('shifts')->nullOnDelete(); $table->foreignId('period_id')->nullable()->after('shift_id')->constrained('routine_periods')->nullOnDelete(); $table->foreignId('room_id')->nullable()->after('period_id')->constrained('routine_rooms')->nullOnDelete(); }); }
    public function down(): void { Schema::table('routines', function (Blueprint $table) { $table->dropForeign(['academic_year_id']); $table->dropForeign(['shift_id']); $table->dropForeign(['period_id']); $table->dropForeign(['room_id']); $table->dropColumn(['academic_year_id','shift_id','period_id','room_id']); }); }
};
