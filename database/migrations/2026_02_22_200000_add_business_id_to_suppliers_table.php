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
        if (!Schema::hasColumn('suppliers', 'business_id')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->foreignId('business_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('businesses')
                    ->cascadeOnDelete();

                $table->index('business_id');
            });
        }

        // Preserve legacy data when there is only one business.
        $businessCount = DB::table('businesses')->count();
        if ($businessCount === 1) {
            $businessId = DB::table('businesses')->value('id');
            if ($businessId) {
                DB::table('suppliers')
                    ->whereNull('business_id')
                    ->update(['business_id' => $businessId]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasColumn('suppliers', 'business_id')) {
            return;
        }

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_id');
        });
    }
};
