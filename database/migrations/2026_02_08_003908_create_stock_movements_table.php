<?php

// database/migrations/xxxx_xx_xx_create_stock_movements_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::create('stock_movements', function (Blueprint $table) {
      $table->id();
      $table->foreignId('business_id')->constrained()->cascadeOnDelete();

      $table->foreignId('product_id')->constrained()->cascadeOnDelete();
      $table->string('direction'); // in|out
      $table->string('reason');    // invoice_issue|invoice_void|adjustment...
      $table->decimal('quantity', 12, 3);
      $table->decimal('unit_cost', 12, 2)->default(0);

      $table->string('source_type')->nullable(); // Invoice
      $table->unsignedBigInteger('source_id')->nullable();

      $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
      $table->text('notes')->nullable();

      $table->timestamps();

      $table->index(['business_id','product_id']);
      $table->index(['business_id','source_type','source_id']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('stock_movements');
  }
};
