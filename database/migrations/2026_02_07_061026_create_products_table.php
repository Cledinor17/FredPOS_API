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
       Schema::create('products', function (Blueprint $table) {
    $table->id();
    $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
    $table->foreignId('category_id')->nullable(); // Optionnel si pas de catégorie
    $table->string('name');
    $table->string('sku')->nullable();
    $table->enum('type', ['product', 'service'])->default('product');
    $table->decimal('cost_price', 10, 2)->default(0);
    $table->decimal('selling_price', 10, 2);
    $table->boolean('track_inventory')->default(true);
    $table->decimal('stock', 12, 3)->default(0)->change();
    $table->integer('stock_quantity')->default(0);
    $table->integer('alert_quantity')->default(5);
    $table->string('image_path')->nullable();
   $table->boolean('is_active')->default(true); // Pour désactiver un produit sans le supprimer
    $table->timestamps();

    $table->unique(['business_id', 'sku']);
    $table->index(['business_id', 'name']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
