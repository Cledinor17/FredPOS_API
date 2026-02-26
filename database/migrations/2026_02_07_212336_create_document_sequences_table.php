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
          Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // proforma, invoice, sale, purchase...
            $table->string('prefix')->default('PF-');
            $table->unsignedInteger('next_number')->default(1);
            $table->unsignedInteger('padding')->default(6); // 000001
            $table->timestamps();

            $table->unique(['business_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
