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
       Schema::create('audit_logs', function (Blueprint $table) {
      $table->id();
      $table->foreignId('business_id')->constrained()->cascadeOnDelete();

      $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

      $table->uuid('group_id')->nullable(); // regroupe plusieurs logs d'une même opération
      $table->string('action');             // ex: invoice.payment_added, invoice.void, period.closed...
      $table->string('entity_type')->nullable(); // Invoice, InvoicePayment, JournalEntry...
      $table->unsignedBigInteger('entity_id')->nullable();

      $table->string('ip', 45)->nullable();
      $table->text('user_agent')->nullable();

      $table->json('before')->nullable();
      $table->json('after')->nullable();
      $table->json('metadata')->nullable();

      $table->timestamp('occurred_at')->useCurrent();

      $table->timestamps();

      $table->index(['business_id','occurred_at']);
      $table->index(['entity_type','entity_id']);
      $table->index(['business_id','action']);
      $table->index(['business_id','user_id']);
      $table->index(['business_id','group_id']);
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
