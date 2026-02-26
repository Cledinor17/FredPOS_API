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
Schema::create('businesses', function (Blueprint $table) {
    $table->id();
    $table->string('name');
     $table->string('legal_name')->nullable();
    $table->string('slug')->unique();
    $table->string('timezone')->default('Europe/London');
    $table->string('currency')->default('GBP');
    // $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->string('email')->nullable();
    $table->string('phone')->nullable();
    $table->string('website')->nullable();
    $table->string('tax_number')->nullable(); // NIF/TVA
    $table->json('address')->nullable();      // {line1,line2,city,state,zip,country}
    $table->string('logo_path')->nullable();  // storage path (option)
    $table->text('invoice_footer')->nullable();
    $table->string('status')->default('active');

    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
