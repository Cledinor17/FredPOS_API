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
Schema::create('proforma_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->foreignId('proforma_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('product_id')->nullable(); // si tu as products

            $table->string('name');              // snapshot
            $table->string('sku')->nullable();   // snapshot
            $table->text('description')->nullable();

            $table->decimal('quantity', 12, 3)->default(1);
            $table->string('unit')->nullable();  // pcs, kg, hr...
            $table->decimal('unit_price', 12, 2)->default(0);

            $table->string('discount_type')->nullable(); // percent|fixed
            $table->decimal('discount_value', 12, 4)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);

            $table->decimal('tax_rate', 6, 3)->default(0);   // ex 10.000
            $table->decimal('tax_amount', 12, 2)->default(0);

            $table->decimal('line_subtotal', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);

            $table->unsignedInteger('sort_order')->default(1);

            $table->timestamps();

            $table->index(['business_id', 'proforma_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proforma_items');
    }
};
