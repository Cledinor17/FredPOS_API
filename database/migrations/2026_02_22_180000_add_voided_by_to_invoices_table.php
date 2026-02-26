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
        if (!Schema::hasColumn('invoices', 'voided_by')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('invoices', 'voided_by')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropConstrainedForeignId('voided_by');
            });
        }
    }
};
