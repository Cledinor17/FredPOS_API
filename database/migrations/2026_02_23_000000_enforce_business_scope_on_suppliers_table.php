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

        $firstBusinessId = DB::table('businesses')->orderBy('id')->value('id');
        if ($firstBusinessId) {
            DB::table('suppliers')
                ->whereNull('business_id')
                ->update(['business_id' => $firstBusinessId]);
        }

        $nullCount = (int) DB::table('suppliers')->whereNull('business_id')->count();
        if ($nullCount === 0 && DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `suppliers` MODIFY `business_id` BIGINT UNSIGNED NOT NULL');
        }

        if (!$this->indexExists('suppliers', 'suppliers_business_id_name_index')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->index(['business_id', 'name'], 'suppliers_business_id_name_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if ($this->indexExists('suppliers', 'suppliers_business_id_name_index')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->dropIndex('suppliers_business_id_name_index');
            });
        }

        if (Schema::hasColumn('suppliers', 'business_id') && DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `suppliers` MODIFY `business_id` BIGINT UNSIGNED NULL');
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $database = DB::getDatabaseName();
        $driver = DB::getDriverName();

        if ($driver !== 'mysql') {
            return false;
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};

