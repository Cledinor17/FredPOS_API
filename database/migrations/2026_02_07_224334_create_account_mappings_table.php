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
     Schema::create('account_mappings', function (Blueprint $table) {
      $table->id();
      $table->foreignId('business_id')->constrained()->cascadeOnDelete();
      $table->string('key'); // AR, CASH, SALES, TAX_PAYABLE...
      $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
      $table->timestamps();

      $table->unique(['business_id','key']);
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_mappings');
    }
};
