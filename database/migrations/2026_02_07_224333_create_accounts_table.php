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
    Schema::create('accounts', function (Blueprint $table) {
      $table->id();
      $table->foreignId('business_id')->constrained()->cascadeOnDelete();

      $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();

      $table->string('code'); // ex: 1000, 1100...
      $table->string('name'); // Cash, Accounts Receivable...
      $table->string('type'); // asset|liability|equity|income|expense
      $table->string('subtype')->nullable(); // optional (bank, cash, tax, sales, etc.)
      $table->string('normal_balance')->default('debit'); // debit|credit
      $table->boolean('is_system')->default(false);
      $table->boolean('is_active')->default(true);
      $table->text('description')->nullable();

      $table->timestamps();

      $table->unique(['business_id','code']);
      $table->index(['business_id','type']);
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
