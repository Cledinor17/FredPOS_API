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
    Schema::create('journal_lines', function (Blueprint $table) {
      $table->id();
      $table->foreignId('business_id')->constrained()->cascadeOnDelete();

      $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
      $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();

      $table->unsignedInteger('line_no')->default(1);
      $table->string('description')->nullable();

      $table->decimal('debit', 12, 2)->default(0);
      $table->decimal('credit', 12, 2)->default(0);

      // suivi par tiers
      $table->unsignedBigInteger('customer_id')->nullable();
      $table->unsignedBigInteger('vendor_id')->nullable();

      $table->timestamps();

      $table->index(['business_id','account_id']);
      $table->index(['business_id','journal_entry_id']);
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
    }
};
