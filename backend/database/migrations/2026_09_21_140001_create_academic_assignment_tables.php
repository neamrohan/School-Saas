<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('class_section_assignments', function (Blueprint $table) { $table->id(); $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete(); $table->foreignId('school_class_id')->constrained('school_classes')->cascadeOnDelete(); $table->foreignId('section_id')->constrained('sections')->cascadeOnDelete(); $table->timestamps(); $table->unique(['school_id','school_class_id','section_id']); });
        Schema::create('class_subject_assignments', function (Blueprint $table) { $table->id(); $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete(); $table->foreignId('school_class_id')->constrained('school_classes')->cascadeOnDelete(); $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete(); $table->timestamps(); $table->unique(['school_id','school_class_id','subject_id']); });
        Schema::create('student_subject_assignments', function (Blueprint $table) { $table->id(); $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete(); $table->foreignId('student_id')->constrained('students')->cascadeOnDelete(); $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete(); $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete(); $table->timestamps(); $table->unique(['school_id','student_id','subject_id','academic_year_id'], 'student_subject_unique'); });
    }
    public function down(): void { Schema::dropIfExists('student_subject_assignments'); Schema::dropIfExists('class_subject_assignments'); Schema::dropIfExists('class_section_assignments'); }
};
