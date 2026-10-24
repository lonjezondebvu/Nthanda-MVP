<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->string('role')->default('learner'));
        Schema::create('lessons', function (Blueprint $t) {
            $t->id();
            $t->string('manifest_id');
            $t->string('version');
            $t->string('title');
            $t->string('sha256')->unique();
            $t->string('directory');
            $t->json('report');
            $t->timestamps();
            $t->unique(['manifest_id', 'version']);
        });
        Schema::create('content_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $t->string('kind');
            $t->string('item_id');
            $t->boolean('enabled')->default(true);
            $t->boolean('deleted')->default(false);
            $t->integer('position')->nullable();
            $t->timestamps();
            $t->unique(['lesson_id', 'kind', 'item_id']);
        });
        Schema::create('progress_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $t->string('section_id');
            $t->boolean('read')->default(false);
            $t->integer('confidence')->nullable();
            $t->json('activities')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'lesson_id', 'section_id']);
        });
        Schema::create('assessment_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $t->string('kind');
            $t->string('status');
            $t->json('responses');
            $t->json('result')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['assessment_attempts', 'progress_records', 'content_settings', 'lessons'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('role'));
    }
};
