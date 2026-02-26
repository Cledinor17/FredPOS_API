<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
      public function up(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->string('kind')->default('payment')->after('invoice_id'); // payment|refund
            $table->index(['business_id','invoice_id','kind']);
        });
    }

    public function down(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->dropIndex(['business_id','invoice_id','kind']);
            $table->dropColumn('kind');
        });
    }
};
