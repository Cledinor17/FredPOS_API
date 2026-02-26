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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('number'); // FA-000001 / INV-000001
            $table->string('status')->default('issued');
            // issued|partially_paid|paid|overdue|void|refunded

            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable(); // issue_date + terms

            $table->string('currency')->default('USD');
            $table->decimal('exchange_rate', 12, 6)->default(1);

            $table->string('reference')->nullable();  // PO/ref interne
            $table->string('title')->nullable();      // "Facture"
            $table->unsignedInteger('payment_terms_days')->nullable();

            $table->foreignId('salesperson_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->json('billing_address')->nullable();
            $table->json('shipping_address')->nullable();

            $table->string('shipping_method')->nullable();
            $table->decimal('shipping_cost', 12, 2)->default(0);

            $table->string('discount_type')->nullable(); // percent|fixed
            $table->decimal('discount_value', 12, 4)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);

            $table->boolean('is_tax_inclusive')->default(false);

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            // Paiements / solde (stockés pour requêtes rapides)
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('balance_due', 12, 2)->default(0);

            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->text('internal_notes')->nullable();

            // Traçabilité conversion
            $table->unsignedBigInteger('source_document_id')->nullable(); // sales_documents.id
            $table->string('source_document_type')->nullable(); // quote/proforma
            $table->timestamp('voided_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id','number']);
            $table->index(['business_id','status','issue_date','due_date']);
            $table->index(['business_id','customer_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
