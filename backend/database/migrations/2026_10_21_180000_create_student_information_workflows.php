<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_admission_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('guardian_name');
            $table->string('guardian_phone');
            $table->date('date_of_birth')->nullable();
            $table->string('gender')->nullable();
            $table->text('address')->nullable();
            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();
            $table->foreignId('class_id')->constrained('school_classes')->restrictOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_student_id')->nullable()->unique()->constrained('students')->nullOnDelete();
            $table->timestamps();
            $table->index(['school_id', 'status', 'created_at']);
        });

        Schema::create('student_re_admissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('previous_academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();
            $table->foreignId('previous_class_id')->nullable()->constrained('school_classes')->nullOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('school_classes')->nullOnDelete();
            $table->foreignId('previous_section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['student_id', 'academic_year_id']);
            $table->index(['school_id', 'academic_year_id']);
        });

        Schema::create('waiver_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('calculation_type', 20);
            $table->decimal('value', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['school_id', 'name']);
        });

        Schema::create('student_waivers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('student_fee_id')->constrained('student_fees')->restrictOnDelete();
            $table->foreignId('waiver_type_id')->constrained('waiver_types')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('active');
            $table->text('reason')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'student_id', 'status']);
            $table->index(['student_fee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_waivers');
        Schema::dropIfExists('waiver_types');
        Schema::dropIfExists('student_re_admissions');
        Schema::dropIfExists('online_admission_applications');
    }
};
