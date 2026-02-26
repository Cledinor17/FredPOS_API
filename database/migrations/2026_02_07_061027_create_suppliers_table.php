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
    Schema::create('suppliers', function (Blueprint $table) {
    $table->id();
    $table->string('department'); // Séparation Hotel/Quincaillerie
    $table->string('name');
    $table->string('phone')->nullable();
    $table->decimal('balance', 12, 2)->default(0); // Dettes
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
