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
        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();

            $table->string('method')->default('cash');
            // cash|card|bank|moncash|cheque|other
            $table->decimal('amount', 12, 2);
            $table->string('currency')->default('USD');
            $table->decimal('exchange_rate', 12, 6)->default(1);

            $table->timestamp('paid_at')->nullable();
            $table->string('reference')->nullable(); // ref transaction
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['business_id','invoice_id']);
            $table->index(['business_id','method','paid_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');
    }
};
