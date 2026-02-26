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
       Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('code')->nullable();               // code client interne
            $table->string('name');                          // obligatoire
            $table->string('company_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();

            $table->json('billing_address')->nullable();      // {line1,line2,city,state,zip,country}
            $table->json('shipping_address')->nullable();

            $table->string('tax_number')->nullable();         // NIF/TVA etc
            $table->string('currency')->nullable();           // si client en devise particulière
            $table->unsignedInteger('payment_terms_days')->nullable(); // ex 30
            $table->decimal('credit_limit', 12, 2)->nullable();

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['business_id', 'name']);
            $table->unique(['business_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
