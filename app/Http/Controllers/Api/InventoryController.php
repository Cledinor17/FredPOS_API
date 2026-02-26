<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class InventoryController extends Controller
{
    private array $tableColumnCache = [];

    public function summary(Request $request, string $business)
    {
        $businessId = $this->currentBusinessIdOrFail();

        $query = DB::table('products')->where('business_id', $businessId);
        $search = trim((string) $request->query('q', ''));

        if ($search !== '') {
            $query->where(function ($sub) use ($search) {
                $sub->where('name', 'like', '%' . $search . '%');
                if ($this->hasColumn('products', 'sku')) {
                    $sub->orWhere('sku', 'like', '%' . $search . '%');
                }
                if ($this->hasColumn('products', 'barcode')) {
                    $sub->orWhere('barcode', 'like', '%' . $search . '%');
                }
            });
        }

        $rows = $query->get();

        $stats = [
            'total_products' => 0,
            'tracked_products' => 0,
            'low_stock_count' => 0,
            'out_of_stock_count' => 0,
            'stock_units' => 0.0,
            'stock_value' => 0.0,
            'potential_revenue' => 0.0,
        ];
        $lowStock = [];

        foreach ($rows as $row) {
            $stats['total_products']++;

            $trackInventory = (bool) ($row->track_inventory ?? false);
            if (!$trackInventory) {
                continue;
            }

            $stats['tracked_products']++;

            $stock = $this->toFloat($row->stock ?? $row->stock_quantity ?? 0);
            $alert = max(0, (int) ($row->alert_quantity ?? 0));
            $cost = $this->toFloat($row->cost_price ?? 0);
            $price = $this->toFloat($row->selling_price ?? 0);

            $stats['stock_units'] += $stock;
            $stats['stock_value'] += ($stock * $cost);
            $stats['potential_revenue'] += ($stock * $price);

            if ($stock <= 0.000001) {
                $stats['out_of_stock_count']++;
            } elseif ($alert > 0 && $stock <= $alert) {
                $stats['low_stock_count']++;
                $lowStock[] = [
                    'id' => (string) $row->id,
                    'name' => (string) ($row->name ?? ''),
                    'sku' => (string) ($row->sku ?? ''),
                    'stock' => round($stock, 3),
                    'alert_quantity' => $alert,
                ];
            }
        }

        usort($lowStock, function (array $a, array $b) {
            return ($a['stock'] <=> $b['stock']) ?: strcmp($a['name'], $b['name']);
        });
        $lowStock = array_slice($lowStock, 0, 12);

        return response()->json([
            'summary' => [
                'total_products' => $stats['total_products'],
                'tracked_products' => $stats['tracked_products'],
                'low_stock_count' => $stats['low_stock_count'],
                'out_of_stock_count' => $stats['out_of_stock_count'],
                'stock_units' => round($stats['stock_units'], 3),
                'stock_value' => round($stats['stock_value'], 2),
                'potential_revenue' => round($stats['potential_revenue'], 2),
            ],
            'low_stock_products' => $lowStock,
        ]);
    }

    public function movements(Request $request, string $business)
    {
        $businessId = $this->currentBusinessIdOrFail();

        if (!Schema::hasTable('stock_movements')) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'per_page' => 20,
                    'total' => 0,
                    'last_page' => 1,
                ],
            ]);
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min((int) $request->query('per_page', 20), 100));

        $query = $this->movementBaseQuery($businessId);
        $this->applyMovementFilters($query, $request);

        $total = (clone $query)->count('stock_movements.id');
        $rows = (clone $query)
            ->orderByDesc('stock_movements.id')
            ->forPage($page, $perPage)
            ->get();

        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));

        return response()->json([
            'data' => $rows->map(fn ($row) => [
                'id' => (string) $row->id,
                'product_id' => (string) $row->product_id,
                'product_name' => (string) ($row->product_name ?? ''),
                'product_sku' => (string) ($row->product_sku ?? ''),
                'direction' => (string) ($row->direction ?? ''),
                'reason' => (string) ($row->reason ?? ''),
                'quantity' => round($this->toFloat($row->quantity ?? 0), 3),
                'unit_cost' => round($this->toFloat($row->unit_cost ?? 0), 2),
                'source_type' => $row->source_type ? (string) $row->source_type : null,
                'source_id' => isset($row->source_id) ? (string) $row->source_id : null,
                'notes' => $row->notes ? (string) $row->notes : null,
                'created_at' => $row->created_at ? (string) $row->created_at : null,
                'created_by' => isset($row->created_by) ? (string) $row->created_by : null,
                'created_by_name' => isset($row->created_by_name) && $row->created_by_name
                    ? (string) $row->created_by_name
                    : null,
                'created_by_email' => isset($row->created_by_email) && $row->created_by_email
                    ? (string) $row->created_by_email
                    : null,
            ])->values()->all(),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }

    public function movementsCsv(Request $request, string $business)
    {
        $businessId = $this->currentBusinessIdOrFail();

        $filename = 'inventory-movements-' . now()->format('Ymd_His') . '.csv';

        if (!Schema::hasTable('stock_movements')) {
            return response()->streamDownload(function () {
                $out = fopen('php://output', 'w');
                fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
                fputcsv($out, ['Date', 'Product', 'SKU', 'Direction', 'Reason', 'Quantity', 'Unit Cost', 'Actor', 'Actor ID', 'Source', 'Notes']);
                fclose($out);
            }, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        $query = $this->movementBaseQuery($businessId);
        $this->applyMovementFilters($query, $request);

        $rows = $query->orderByDesc('stock_movements.id')->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($out, ['Date', 'Product', 'SKU', 'Direction', 'Reason', 'Quantity', 'Unit Cost', 'Actor', 'Actor ID', 'Source', 'Notes']);

            foreach ($rows as $row) {
                $source = trim((string) (($row->source_type ?? '') . (isset($row->source_id) && $row->source_id !== null ? '#' . $row->source_id : '')));
                $actorName = isset($row->created_by_name) && $row->created_by_name ? (string) $row->created_by_name : '';
                $actorId = isset($row->created_by) && $row->created_by !== null ? (string) $row->created_by : '';

                fputcsv($out, [
                    $row->created_at ? (string) $row->created_at : '',
                    (string) ($row->product_name ?? ''),
                    (string) ($row->product_sku ?? ''),
                    (string) ($row->direction ?? ''),
                    (string) ($row->reason ?? ''),
                    round($this->toFloat($row->quantity ?? 0), 3),
                    round($this->toFloat($row->unit_cost ?? 0), 2),
                    $actorName,
                    $actorId,
                    $source,
                    (string) ($row->notes ?? ''),
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function adjust(Request $request, string $business)
    {
        $businessId = $this->currentBusinessIdOrFail();

        $data = $request->validate([
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where(
                    fn ($q) => $q->where('business_id', $businessId)
                ),
            ],
            'operation' => ['required', Rule::in(['increase', 'decrease', 'set'])],
            'quantity' => ['required', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $result = DB::transaction(function () use ($businessId, $data) {
            $product = DB::table('products')
                ->where('business_id', $businessId)
                ->where('id', (int) $data['product_id'])
                ->lockForUpdate()
                ->first();

            if (!$product) {
                abort(404, 'Product not found.');
            }

            if (!(bool) ($product->track_inventory ?? false)) {
                abort(422, 'Inventory is not tracked for this product.');
            }

            $oldStock = $this->toFloat($product->stock ?? $product->stock_quantity ?? 0);
            $quantity = round($this->toFloat($data['quantity']), 3);
            $operation = (string) $data['operation'];

            $direction = 'in';
            $movementQty = 0.0;
            $newStock = $oldStock;

            if ($operation === 'increase') {
                if ($quantity <= 0) {
                    abort(422, 'Increase quantity must be greater than zero.');
                }
                $newStock = $oldStock + $quantity;
                $movementQty = $quantity;
                $direction = 'in';
            } elseif ($operation === 'decrease') {
                if ($quantity <= 0) {
                    abort(422, 'Decrease quantity must be greater than zero.');
                }
                if ($quantity > $oldStock + 0.000001) {
                    abort(422, 'Insufficient stock for this adjustment.');
                }
                $newStock = $oldStock - $quantity;
                $movementQty = $quantity;
                $direction = 'out';
            } else {
                $newStock = max(0, $quantity);
                $delta = round($newStock - $oldStock, 3);
                if (abs($delta) <= 0.000001) {
                    abort(422, 'No stock change detected.');
                }
                $direction = $delta > 0 ? 'in' : 'out';
                $movementQty = abs($delta);
            }

            $newStock = round(max(0, $newStock), 3);
            $unitCost = isset($data['unit_cost']) ? $this->toFloat($data['unit_cost']) : $this->toFloat($product->cost_price ?? 0);
            $reason = trim((string) ($data['reason'] ?? 'manual_adjustment'));
            if ($reason === '') {
                $reason = 'manual_adjustment';
            }

            $now = now();
            $actorId = auth()->id();
            $actorName = auth()->user()?->name;
            $actorEmail = auth()->user()?->email;

            DB::table('products')
                ->where('id', (int) $product->id)
                ->update($this->filterColumns('products', [
                    'stock' => $newStock,
                    'stock_quantity' => (int) round($newStock),
                    'updated_at' => $now,
                ]));

            $movementId = null;
            if (Schema::hasTable('stock_movements')) {
                $movementId = DB::table('stock_movements')->insertGetId($this->filterColumns('stock_movements', [
                    'business_id' => $businessId,
                    'product_id' => (int) $product->id,
                    'direction' => $direction,
                    'reason' => $reason,
                    'quantity' => $movementQty,
                    'unit_cost' => $unitCost,
                    'source_type' => 'ManualAdjustment',
                    'source_id' => null,
                    'created_by' => $actorId,
                    'notes' => $data['notes'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }

            return [
                'product' => [
                    'id' => (string) $product->id,
                    'name' => (string) ($product->name ?? ''),
                    'sku' => (string) ($product->sku ?? ''),
                    'old_stock' => round($oldStock, 3),
                    'new_stock' => round($newStock, 3),
                ],
                'movement' => [
                    'id' => $movementId ? (string) $movementId : null,
                    'direction' => $direction,
                    'reason' => $reason,
                    'quantity' => round($movementQty, 3),
                    'unit_cost' => round($unitCost, 2),
                    'created_by' => $actorId ? (string) $actorId : null,
                    'created_by_name' => $actorName ? (string) $actorName : null,
                    'created_by_email' => $actorEmail ? (string) $actorEmail : null,
                ],
            ];
        });

        return response()->json([
            'message' => 'Stock adjusted successfully.',
            'data' => $result,
        ]);
    }

    private function currentBusinessIdOrFail(): int
    {
        $currentBusiness = app()->bound('currentBusiness') ? app('currentBusiness') : null;
        if (!$currentBusiness) {
            abort(403, 'Business context is required.');
        }

        return (int) $currentBusiness->id;
    }

    private function toFloat(mixed $value, float $default = 0): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && trim($value) !== '' && is_numeric($value)) {
            return (float) $value;
        }

        return $default;
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

    private function movementBaseQuery(int $businessId)
    {
        $query = DB::table('stock_movements')
            ->leftJoin('products', 'products.id', '=', 'stock_movements.product_id')
            ->where('stock_movements.business_id', $businessId)
            ->select([
                'stock_movements.*',
                'products.name as product_name',
                'products.sku as product_sku',
            ]);

        if (Schema::hasTable('users') && $this->hasColumn('stock_movements', 'created_by')) {
            $query
                ->leftJoin('users as movement_users', 'movement_users.id', '=', 'stock_movements.created_by')
                ->addSelect([
                    'movement_users.name as created_by_name',
                    'movement_users.email as created_by_email',
                ]);
        }

        return $query;
    }

    private function applyMovementFilters($query, Request $request): void
    {
        if ($request->filled('product_id')) {
            $query->where('stock_movements.product_id', (int) $request->product_id);
        }

        if ($request->filled('direction')) {
            $direction = strtolower((string) $request->direction);
            if (in_array($direction, ['in', 'out'], true)) {
                $query->where('stock_movements.direction', $direction);
            }
        }

        if ($request->filled('reason')) {
            $query->where('stock_movements.reason', (string) $request->reason);
        }

        if ($request->filled('from')) {
            $query->whereDate('stock_movements.created_at', '>=', (string) $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('stock_movements.created_at', '<=', (string) $request->to);
        }

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $query->where(function ($sub) use ($search) {
                $sub->where('products.name', 'like', '%' . $search . '%')
                    ->orWhere('products.sku', 'like', '%' . $search . '%')
                    ->orWhere('stock_movements.notes', 'like', '%' . $search . '%')
                    ->orWhere('stock_movements.reason', 'like', '%' . $search . '%');

                if (Schema::hasTable('users') && $this->hasColumn('stock_movements', 'created_by')) {
                    $sub->orWhere('movement_users.name', 'like', '%' . $search . '%')
                        ->orWhere('movement_users.email', 'like', '%' . $search . '%');
                }
            });
        }
    }
}
