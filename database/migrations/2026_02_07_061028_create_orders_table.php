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
     Schema::create('orders', function (Blueprint $table) {
    $table->id();
    $table->foreignId('business_id')->constrained()->cascadeOnDelete(); // AJOUTÉ
    $table->string('invoice_number')->unique();
    $table->foreignId('user_id');
    $table->string('department');
    $table->foreignId('customer_id')->nullable();
    $table->foreignId('room_id')->nullable(); // Lien avec l'hôtel
    $table->decimal('total_amount', 12, 2);
    $table->decimal('paid_amount', 12, 2)->default(0);
    $table->enum('status', ['pending', 'on_hold', 'completed', 'cancelled'])->default('pending');
    $table->string('payment_method')->nullable();
    $table->text('note')->nullable();
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
