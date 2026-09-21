<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['school_id', 'name']);
        });

        Schema::create('fee_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('fee_categories')->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['school_id', 'name']);
            $table->index(['school_id', 'category_id']);
        });

        Schema::create('fee_pricings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('fee_item_id')->constrained('fee_items')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('school_classes')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['school_id', 'fee_item_id', 'academic_year_id', 'class_id'], 'fee_pricing_configuration_unique');
        });

        Schema::create('monthly_fee_setups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('fee_item_id')->constrained('fee_items')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('school_classes')->nullOnDelete();
            $table->unsignedTinyInteger('month');
            $table->decimal('amount', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['school_id', 'fee_item_id', 'academic_year_id', 'class_id', 'month'], 'monthly_fee_configuration_unique');
        });

        Schema::table('student_fees', function (Blueprint $table) {
            $table->foreignId('fee_item_id')->nullable()->after('fee_type_id')->constrained('fee_items')->nullOnDelete();
            $table->foreignId('academic_year_id')->nullable()->after('fee_item_id')->constrained('academic_years')->nullOnDelete();
            $table->foreignId('class_id')->nullable()->after('academic_year_id')->constrained('school_classes')->nullOnDelete();
            $table->foreignId('section_id')->nullable()->after('class_id')->constrained('sections')->nullOnDelete();
            $table->unsignedTinyInteger('month')->nullable()->after('section_id');
            $table->unique(['school_id', 'student_id', 'fee_item_id', 'academic_year_id', 'month'], 'student_fee_generation_unique');
        });
    }

    public function down(): void
    {
        Schema::table('student_fees', function (Blueprint $table) {
            $table->dropUnique('student_fee_generation_unique');
            $table->dropForeign(['fee_item_id']);
            $table->dropForeign(['academic_year_id']);
            $table->dropForeign(['class_id']);
            $table->dropForeign(['section_id']);
            $table->dropColumn(['fee_item_id', 'academic_year_id', 'class_id', 'section_id', 'month']);
        });
        Schema::dropIfExists('monthly_fee_setups');
        Schema::dropIfExists('fee_pricings');
        Schema::dropIfExists('fee_items');
        Schema::dropIfExists('fee_categories');
    }
};
