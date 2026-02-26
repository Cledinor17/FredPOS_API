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
      Schema::create('rooms', function (Blueprint $table) {
        $table->id();
        $table->string('number'); // "101", "102"
        $table->string('type'); // "Simple", "Double", "Suite"
        $table->decimal('price_per_night', 10, 2);
        // Status : dispo, occupée, nettoyage, maintenance
        $table->enum('status', ['available', 'occupied', 'cleaning', 'maintenance'])->default('available');
        $table->timestamps();
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
