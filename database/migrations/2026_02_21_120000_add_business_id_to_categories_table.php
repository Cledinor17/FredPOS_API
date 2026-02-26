<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('categories', 'business_id')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->foreignId('business_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('businesses')
                    ->cascadeOnDelete();

                $table->index('business_id');
            });
        }

        try {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropUnique('categories_slug_unique');
            });
        } catch (Throwable $e) {
            // Index deja absent, on continue.
        }

        try {
            Schema::table('categories', function (Blueprint $table) {
                $table->unique(['business_id', 'slug'], 'categories_business_id_slug_unique');
            });
        } catch (Throwable $e) {
            // Index deja present, on continue.
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasColumn('categories', 'business_id')) {
            return;
        }

        try {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropUnique('categories_business_id_slug_unique');
            });
        } catch (Throwable $e) {
            // Index deja absent, on continue.
        }

        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_id');
        });

        try {
            Schema::table('categories', function (Blueprint $table) {
                $table->unique('slug');
            });
        } catch (Throwable $e) {
            // Index deja present, on continue.
        }
    }
};
