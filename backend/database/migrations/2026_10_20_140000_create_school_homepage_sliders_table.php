<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_homepage_sliders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->text('description')->nullable();
            $table->string('image_path');
            $table->string('mobile_image_path')->nullable();
            $table->string('button_text')->nullable();
            $table->string('button_url', 2048)->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('status', 20)->default('draft');
            $table->timestamps();

            $table->index(['school_id', 'is_active', 'status', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_homepage_sliders');
    }
};
