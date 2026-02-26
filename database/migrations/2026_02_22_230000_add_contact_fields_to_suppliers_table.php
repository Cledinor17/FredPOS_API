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
        Schema::table('suppliers', function (Blueprint $table) {
            if (!Schema::hasColumn('suppliers', 'business_id')) {
                $table->foreignId('business_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('businesses')
                    ->cascadeOnDelete();
                $table->index('business_id');
            }

            if (!Schema::hasColumn('suppliers', 'contact_person')) {
                $table->string('contact_person', 190)->nullable()->after('name');
            }

            if (!Schema::hasColumn('suppliers', 'address')) {
                $table->string('address', 255)->nullable()->after('phone');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            if (Schema::hasColumn('suppliers', 'address')) {
                $table->dropColumn('address');
            }

            if (Schema::hasColumn('suppliers', 'contact_person')) {
                $table->dropColumn('contact_person');
            }
        });
    }
};

