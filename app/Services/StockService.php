<?php
namespace App\Services;

use App\Models\{Product, StockMovement, Invoice};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StockService
{
  private array $tableColumnCache = [];

  private function tableColumns(string $table): array
  {
    if (!array_key_exists($table, $this->tableColumnCache)) {
      $this->tableColumnCache[$table] = Schema::hasTable($table)
        ? Schema::getColumnListing($table)
        : [];
    }

    return $this->tableColumnCache[$table];
  }

  private function hasColumn(string $table, string $column): bool
  {
    return in_array($column, $this->tableColumns($table), true);
  }

  private function filterColumns(string $table, array $payload): array
  {
    $columns = $this->tableColumns($table);

    return array_filter(
      $payload,
      static fn ($value, $column) => in_array($column, $columns, true),
      ARRAY_FILTER_USE_BOTH
    );
  }

  private function invoiceMovementExists(Invoice $invoice, string $reason): bool
  {
    return StockMovement::query()
      ->where('source_type', 'Invoice')
      ->where('source_id', $invoice->id)
      ->where('reason', $reason)
      ->exists();
  }

  public function issueInvoiceStock(Invoice $invoice): void
  {
    DB::transaction(function () use ($invoice) {
      $invoice->load('items');
      if ($invoice->items->isEmpty()) {
        return;
      }

      if ($this->invoiceMovementExists($invoice, 'invoice_issue')) {
        return;
      }

      $requiredByProductId = [];
      foreach ($invoice->items as $it) {
        if (!$it->product_id) {
          continue;
        }
        $qty = (float) $it->quantity;
        if ($qty <= 0) {
          continue;
        }
        $productId = (int) $it->product_id;
        $requiredByProductId[$productId] = ($requiredByProductId[$productId] ?? 0) + $qty;
      }

      $lockedProducts = [];
      foreach ($requiredByProductId as $productId => $requiredQty) {
        $product = Product::query()
          ->whereKey($productId)
          ->where('business_id', (int) $invoice->business_id)
          ->lockForUpdate()
          ->first();

        if (!$product || !(bool) ($product->track_inventory ?? false)) {
          continue;
        }

        $available = (float) ($product->stock ?? $product->stock_quantity ?? 0);
        if ($available + 0.000001 < $requiredQty) {
          abort(422, 'Stock insuffisant pour le produit: ' . ($product->name ?? ('#' . $product->id)));
        }

        $lockedProducts[$productId] = $product;
      }

      foreach ($invoice->items as $it) {
        if (!$it->product_id) continue;

        $product = $lockedProducts[(int) $it->product_id] ?? null;
        if (!$product || !$product->track_inventory) continue;

        $qty = (float) $it->quantity;
        if ($qty <= 0) continue;

        StockMovement::create([
          'product_id' => $product->id,
          'direction' => 'out',
          'reason' => 'invoice_issue',
          'quantity' => $qty,
          'unit_cost' => (float) $it->unit_cost,
          'source_type' => 'Invoice',
          'source_id' => $invoice->id,
          'created_by' => auth()->id(),
        ]);

        $currentStock = (float) ($product->stock ?? $product->stock_quantity ?? 0);
        $newStock = round(max(0, $currentStock - $qty), 3);

        $stockPayload = $this->filterColumns('products', [
          'stock' => $newStock,
          'stock_quantity' => (int) floor($newStock),
          'updated_at' => now(),
        ]);

        if (!empty($stockPayload)) {
          DB::table('products')
            ->where('id', (int) $product->id)
            ->update($stockPayload);
        }

        if ($this->hasColumn('products', 'stock')) {
          $product->stock = $newStock;
        }
        if ($this->hasColumn('products', 'stock_quantity')) {
          $product->stock_quantity = (int) floor($newStock);
        }
      }
    });
  }

  public function voidInvoiceStock(Invoice $invoice): void
  {
    DB::transaction(function () use ($invoice) {
      $invoice->load('items');
      if ($invoice->items->isEmpty()) {
        return;
      }

      if ($this->invoiceMovementExists($invoice, 'invoice_void')) {
        return;
      }

      foreach ($invoice->items as $it) {
        if (!$it->product_id) continue;

        $product = Product::query()
          ->whereKey((int) $it->product_id)
          ->where('business_id', (int) $invoice->business_id)
          ->lockForUpdate()
          ->first();

        if (!$product || !$product->track_inventory) continue;

        $qty = (float) $it->quantity;
        if ($qty <= 0) continue;

        StockMovement::create([
          'product_id' => $product->id,
          'direction' => 'in',
          'reason' => 'invoice_void',
          'quantity' => $qty,
          'unit_cost' => (float) $it->unit_cost,
          'source_type' => 'Invoice',
          'source_id' => $invoice->id,
          'created_by' => auth()->id(),
        ]);

        $currentStock = (float) ($product->stock ?? $product->stock_quantity ?? 0);
        $newStock = round($currentStock + $qty, 3);

        $stockPayload = $this->filterColumns('products', [
          'stock' => $newStock,
          'stock_quantity' => (int) floor($newStock),
          'updated_at' => now(),
        ]);

        if (!empty($stockPayload)) {
          DB::table('products')
            ->where('id', (int) $product->id)
            ->update($stockPayload);
        }
      }
    });
  }
}
