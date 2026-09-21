<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('versions', function (Blueprint $table) { $table->id(); $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete(); $table->string('name'); $table->boolean('is_active')->default(true); $table->timestamps(); $table->unique(['school_id','name']); });
        Schema::create('groups_trades', function (Blueprint $table) { $table->id(); $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete(); $table->string('name'); $table->string('code')->nullable(); $table->string('type')->nullable(); $table->boolean('is_active')->default(true); $table->timestamps(); $table->unique(['school_id','name']); });
        Schema::create('academic_years', function (Blueprint $table) { $table->id(); $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete(); $table->string('name'); $table->date('start_date')->nullable(); $table->date('end_date')->nullable(); $table->boolean('is_current')->default(false); $table->boolean('is_active')->default(true); $table->timestamps(); $table->unique(['school_id','name']); });
        Schema::create('transports', function (Blueprint $table) { $table->id(); $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete(); $table->string('name'); $table->string('route')->nullable(); $table->string('vehicle_number')->nullable(); $table->string('driver_name')->nullable(); $table->string('driver_phone')->nullable(); $table->unsignedInteger('capacity')->nullable(); $table->boolean('is_active')->default(true); $table->timestamps(); $table->unique(['school_id','name']); });
        Schema::create('routine_periods', function (Blueprint $table) { $table->id(); $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete(); $table->string('name'); $table->time('start_time'); $table->time('end_time'); $table->unsignedInteger('display_order')->nullable(); $table->boolean('is_active')->default(true); $table->timestamps(); $table->unique(['school_id','name']); });
        Schema::create('routine_rooms', function (Blueprint $table) { $table->id(); $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete(); $table->string('name'); $table->string('code')->nullable(); $table->unsignedInteger('capacity')->nullable(); $table->boolean('is_active')->default(true); $table->timestamps(); $table->unique(['school_id','name']); });
    }
    public function down(): void { Schema::dropIfExists('routine_rooms'); Schema::dropIfExists('routine_periods'); Schema::dropIfExists('transports'); Schema::dropIfExists('academic_years'); Schema::dropIfExists('groups_trades'); Schema::dropIfExists('versions'); }
};
