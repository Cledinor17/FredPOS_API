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
       Schema::create('journal_entries', function (Blueprint $table) {
      $table->id();
      $table->foreignId('business_id')->constrained()->cascadeOnDelete();

      $table->date('entry_date');
      $table->string('action', 50); // invoice_issued | invoice_payment | invoice_void | refund...
      $table->string('status')->default('posted'); // posted|draft|reversed
      $table->text('memo')->nullable();

      // lien vers la source (facture, paiement, etc.)
      $table->string('source_type', 50)->nullable(); // Invoice, InvoicePayment...
      $table->unsignedBigInteger('source_id')->nullable();

      $table->string('currency')->default('USD');
      $table->decimal('exchange_rate', 12, 6)->default(1);

      $table->decimal('total_debit', 12, 2)->default(0);
      $table->decimal('total_credit', 12, 2)->default(0);

      $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
      $table->unsignedBigInteger('reverses_entry_id')->nullable(); // si entry de reversal

      $table->timestamps();

      // Idempotence: une action/source ne doit pas poster 2 fois
      $table->unique(['business_id','action','source_type','source_id']);
      $table->index(['business_id','entry_date']);
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
