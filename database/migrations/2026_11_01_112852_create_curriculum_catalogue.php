<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_classes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['school_class_id', 'name']);
        });
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['subject_id', 'title']);
        });
        Schema::table('lessons', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('school_class_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('disabilities')->nullable();
            $table->string('disability_note')->nullable();
            $table->timestamp('deactivated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('school_class_id');
            $table->dropColumn(['disabilities', 'disability_note', 'deactivated_at']);
        });
        Schema::table('lessons', fn (Blueprint $table) => $table->dropConstrainedForeignId('unit_id'));
        Schema::dropIfExists('units');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('school_classes');
    }
};
