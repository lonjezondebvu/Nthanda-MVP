<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->string('workspace')->default('main')->index();
            $table->dropUnique(['sha256']);
            $table->dropUnique(['manifest_id', 'version']);
            $table->unique(['workspace', 'sha256']);
            $table->unique(['workspace', 'manifest_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('lessons')->select('sha256')->groupBy('sha256')->havingRaw('count(*) > 1')->exists()
            || DB::table('lessons')->select('manifest_id', 'version')->groupBy('manifest_id', 'version')->havingRaw('count(*) > 1')->exists()) {
            throw new RuntimeException('Cannot remove workspace isolation while duplicate curriculum versions exist across workspaces. Export and reconcile those versions before rolling back. No curriculum data has been changed.');
        }
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropUnique(['workspace', 'sha256']);
            $table->dropUnique(['workspace', 'manifest_id', 'version']);
            $table->dropColumn('workspace');
            $table->unique('sha256');
            $table->unique(['manifest_id', 'version']);
        });
    }
};
