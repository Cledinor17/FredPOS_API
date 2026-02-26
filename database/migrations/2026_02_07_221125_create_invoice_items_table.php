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
  Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('product_id')->nullable();

            $table->string('name');
            $table->string('sku')->nullable();
            $table->text('description')->nullable();

            $table->decimal('quantity', 12, 3)->default(1);
            $table->string('unit')->nullable();
            $table->decimal('unit_price', 12, 2)->default(0);

            $table->string('discount_type')->nullable(); // percent|fixed
            $table->decimal('discount_value', 12, 4)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);

            $table->decimal('tax_rate', 6, 3)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);

            $table->decimal('line_subtotal', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);

            $table->unsignedInteger('sort_order')->default(1);

            $table->timestamps();

            $table->index(['business_id','invoice_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
