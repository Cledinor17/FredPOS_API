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
      Schema::create('business_users', function (Blueprint $table) {
      $table->id();
      $table->foreignId('business_id')->constrained()->cascadeOnDelete();
      $table->foreignId('user_id')->constrained()->cascadeOnDelete();

      $table->string('role')->default('staff');
      // owner | admin | manager | accountant | staff

      $table->string('status')->default('active'); // active|invited|disabled
      $table->timestamp('invited_at')->nullable();
      $table->timestamp('joined_at')->nullable();

      $table->json('metadata')->nullable();

      $table->timestamps();

      $table->unique(['business_id','user_id']);
      $table->index(['business_id','role']);
      $table->index(['business_id','status']);

    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_users');
    }
};
