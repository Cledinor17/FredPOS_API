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
         Schema::create('proformas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->string('number'); // PF-000001
            $table->string('status')->default('draft'); // draft|sent|accepted|rejected|expired|converted|cancelled

            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();

            $table->string('currency')->default('USD');
            $table->decimal('exchange_rate', 12, 6)->default(1); // si multi-devise

            $table->string('reference')->nullable();     // ref interne / PO client
            $table->string('title')->nullable();         // "Proforma Invoice"
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

            $table->text('notes')->nullable();           // visible client
            $table->text('terms')->nullable();           // conditions
            $table->text('internal_notes')->nullable();  // interne

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();

            $table->unsignedBigInteger('converted_invoice_id')->nullable(); // si tu ajoutes invoices plus tard
            $table->json('metadata')->nullable(); // champs personnalisés

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'status', 'issue_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proformas');
    }
};
