<?php
// database/migrations/xxxx_xx_xx_add_cost_fields_to_invoice_items_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::table('invoice_items', function (Blueprint $table) {
      $table->decimal('unit_cost', 12, 2)->default(0)->after('unit_price');
      $table->decimal('line_cost_total', 12, 2)->default(0)->after('line_total');
    });
  }

  public function down(): void
  {
    Schema::table('invoice_items', function (Blueprint $table) {
      $table->dropColumn(['unit_cost','line_cost_total']);
    });
  }
};

