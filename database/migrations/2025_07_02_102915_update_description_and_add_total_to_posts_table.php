<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Ensure all existing descriptions are valid JSON
        DB::table('posts')->whereNull('description')->update(['description' => '[]']);
        // Fix all non-JSON descriptions
        foreach (DB::table('posts')->get() as $post) {
            $desc = $post->description;
            json_decode($desc);
            if (json_last_error() !== JSON_ERROR_NONE) {
                DB::table('posts')->where('id', $post->id)->update(['description' => '[]']);
            }
        }

        Schema::table('posts', function (Blueprint $table) {
            // Change description to JSON
            $table->json('description')->change();
            // Add total column
            $table->decimal('total', 10, 2)->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // Revert description to text (or whatever it was before)
            $table->text('description')->change();
            // Drop total column
            $table->dropColumn('total');
        });
    }
};
