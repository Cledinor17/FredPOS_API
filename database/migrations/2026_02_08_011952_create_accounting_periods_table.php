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
          Schema::create('accounting_periods', function (Blueprint $table) {
      $table->id();
      $table->foreignId('business_id')->constrained()->cascadeOnDelete();

      $table->string('name')->nullable(); // ex: 2026-01
      $table->date('start_date');
      $table->date('end_date');

      $table->string('status')->default('open'); // open|closed
      $table->timestamp('closed_at')->nullable();
      $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();

      $table->timestamp('reopened_at')->nullable();
      $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();

      $table->text('notes')->nullable();
      $table->timestamps();

      $table->unique(['business_id','start_date','end_date']);
      $table->index(['business_id','status']);
      $table->index(['business_id','start_date','end_date']);
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};
