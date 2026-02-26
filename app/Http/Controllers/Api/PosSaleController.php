<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PosSaleController extends Controller
{
    private array $tableColumnCache = [];

    public function index(Request $request, string $business)
    {
        $businessId = $this->currentBusinessIdOrFail();

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min((int) $request->query('per_page', 20), 100));

        $query = $this->salesQueryBase($businessId);

        if ($request->filled('status') && $this->hasColumn('invoices', 'status')) {
            $query->where('invoices.status', (string) $request->status);
        }

        if ($request->filled('from') && $this->hasColumn('invoices', 'issue_date')) {
            $query->whereDate('invoices.issue_date', '>=', (string) $request->from);
        }

        if ($request->filled('to') && $this->hasColumn('invoices', 'issue_date')) {
            $query->whereDate('invoices.issue_date', '<=', (string) $request->to);
        }

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $query->where(function ($sub) use ($search) {
                $has = false;

                if ($this->hasColumn('invoices', 'number')) {
                    $sub->where('invoices.number', 'like', '%' . $search . '%');
                    $has = true;
                }

                if ($this->hasColumn('invoices', 'reference')) {
                    if ($has) {
                        $sub->orWhere('invoices.reference', 'like', '%' . $search . '%');
                    } else {
                        $sub->where('invoices.reference', 'like', '%' . $search . '%');
                    }
                    $has = true;
                }

                if ($this->hasColumn('invoices', 'notes')) {
                    if ($has) {
                        $sub->orWhere('invoices.notes', 'like', '%' . $search . '%');
                    } else {
                        $sub->where('invoices.notes', 'like', '%' . $search . '%');
                    }
                    $has = true;
                }

                if (Schema::hasTable('customers') && $this->hasColumn('invoices', 'customer_id')) {
                    if ($has) {
                        $sub->orWhere('customers.name', 'like', '%' . $search . '%');
                    } else {
                        $sub->where('customers.name', 'like', '%' . $search . '%');
                    }
                    $has = true;
                }

                if (Schema::hasTable('users') && $this->hasColumn('invoices', 'created_by')) {
                    if ($has) {
                        $sub->orWhere('created_users.name', 'like', '%' . $search . '%')
                            ->orWhere('created_users.email', 'like', '%' . $search . '%');
                    } else {
                        $sub->where(function ($userSearch) use ($search) {
                            $userSearch->where('created_users.name', 'like', '%' . $search . '%')
                                ->orWhere('created_users.email', 'like', '%' . $search . '%');
                        });
                    }
                    $has = true;
                }

                if (Schema::hasTable('users') && $this->hasColumn('invoices', 'voided_by')) {
                    if ($has) {
                        $sub->orWhere('voided_users.name', 'like', '%' . $search . '%')
                            ->orWhere('voided_users.email', 'like', '%' . $search . '%');
                    } else {
                        $sub->where(function ($userSearch) use ($search) {
                            $userSearch->where('voided_users.name', 'like', '%' . $search . '%')
                                ->orWhere('voided_users.email', 'like', '%' . $search . '%');
                        });
                    }
                    $has = true;
                }

                if (!$has) {
                    $sub->whereRaw('1 = 0');
                }
            });
        }

        $total = (clone $query)->count('invoices.id');
        $rows = (clone $query)
            ->orderByDesc('invoices.id')
            ->forPage($page, $perPage)
            ->get();

        $invoiceIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $aggregates = $this->loadSaleAggregates($invoiceIds);
        $itemCounts = $aggregates['item_counts'];
        $paymentSummaries = $aggregates['payment_summaries'];

        $sales = $rows->map(function ($row) use ($itemCounts, $paymentSummaries) {
            $saleId = (int) $row->id;
            return $this->mapSaleRow(
                $row,
                $itemCounts[$saleId] ?? 0,
                $paymentSummaries[$saleId] ?? $this->defaultPaymentSummary()
            );
        })->values()->all();

        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));

        return response()->json([
            'data' => $sales,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }

    public function show(Request $request, string $business, string $sale)
    {
        $businessId = $this->currentBusinessIdOrFail();
        $saleId = $this->parseSaleId($sale);

        $summary = $this->loadSaleSummary($businessId, $saleId);
        if (!$summary) {
            abort(404, 'Sale not found.');
        }

        $items = [];
        if (Schema::hasTable('invoice_items')) {
            $itemRows = DB::table('invoice_items')
                ->where('invoice_id', $saleId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            $items = $itemRows->map(fn ($item) => [
                'id' => (string) $item->id,
                'product_id' => $item->product_id ? (string) $item->product_id : null,
                'name' => (string) ($item->name ?? ''),
                'sku' => $item->sku ? (string) $item->sku : '',
                'quantity' => (float) ($item->quantity ?? 0),
                'unit_price' => (float) ($item->unit_price ?? 0),
                'tax_rate' => (float) ($item->tax_rate ?? 0),
                'tax_amount' => (float) ($item->tax_amount ?? 0),
                'line_total' => (float) ($item->line_total ?? 0),
            ])->values()->all();
        }

        $payments = [];
        if (Schema::hasTable('invoice_payments')) {
            $hasKind = $this->hasColumn('invoice_payments', 'kind');
            $paymentRowsQuery = DB::table('invoice_payments')
                ->where('invoice_payments.invoice_id', $saleId)
                ->orderByDesc('invoice_payments.id');

            if (Schema::hasTable('users') && $this->hasColumn('invoice_payments', 'received_by')) {
                $paymentRowsQuery
                    ->leftJoin('users as payment_users', 'payment_users.id', '=', 'invoice_payments.received_by')
                    ->select([
                        'invoice_payments.*',
                        'payment_users.name as received_by_name',
                        'payment_users.email as received_by_email',
                    ]);
            } else {
                $paymentRowsQuery->select('invoice_payments.*');
            }

            $paymentRows = $paymentRowsQuery->get();

            $payments = $paymentRows->map(fn ($payment) => [
                'id' => (string) $payment->id,
                'kind' => $hasKind ? (string) ($payment->kind ?? 'payment') : 'payment',
                'method' => (string) ($payment->method ?? ''),
                'amount' => (float) ($payment->amount ?? 0),
                'paid_at' => $payment->paid_at ? (string) $payment->paid_at : null,
                'reference' => $payment->reference ? (string) $payment->reference : null,
                'notes' => $payment->notes ? (string) $payment->notes : null,
                'received_by' => isset($payment->received_by) ? (string) $payment->received_by : null,
                'received_by_name' => isset($payment->received_by_name) && $payment->received_by_name
                    ? (string) $payment->received_by_name
                    : null,
                'received_by_email' => isset($payment->received_by_email) && $payment->received_by_email
                    ? (string) $payment->received_by_email
                    : null,
            ])->values()->all();
        }

        return response()->json([
            'sale' => array_merge($summary, [
                'items' => $items,
                'payments' => $payments,
            ]),
        ]);
    }

    public function refund(Request $request, string $business, string $sale)
    {
        $businessId = $this->currentBusinessIdOrFail();
        $saleId = $this->parseSaleId($sale);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['sometimes', 'string', 'max:50'],
            'paid_at' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:190'],
            'notes' => ['nullable', 'string'],
        ]);

        $updated = DB::transaction(function () use ($businessId, $saleId, $data) {
            $invoice = $this->findSale($businessId, $saleId, true);
            if (!$invoice) {
                abort(404, 'Sale not found.');
            }

            if ((string) ($invoice->status ?? '') === 'void') {
                abort(422, 'Cannot refund a void sale.');
            }

            $currentPaid = round((float) ($invoice->amount_paid ?? 0), 2);
            if ($currentPaid <= 0) {
                abort(422, 'No paid amount available to refund.');
            }

            $refundAmount = round((float) $data['amount'], 2);
            if ($refundAmount > $currentPaid + 0.000001) {
                abort(422, 'Refund exceeds paid amount.');
            }

            $now = now();
            $paymentMethod = $this->mapPaymentMethod((string) ($data['method'] ?? 'cash'));

            DB::table('invoice_payments')->insert($this->filterColumns('invoice_payments', [
                'business_id' => $businessId,
                'invoice_id' => $saleId,
                'kind' => 'refund',
                'method' => $paymentMethod,
                'amount' => $refundAmount,
                'currency' => $invoice->currency ?? 'USD',
                'exchange_rate' => $invoice->exchange_rate ?? 1,
                'paid_at' => $data['paid_at'] ?? $now,
                'reference' => $data['reference'] ?? null,
                'received_by' => auth()->id(),
                'notes' => $data['notes'] ?? null,
                'metadata' => json_encode(['channel' => 'pos']),
                'created_at' => $now,
                'updated_at' => $now,
            ]));

            $total = round((float) ($invoice->total ?? 0), 2);
            $currentBalance = round((float) ($invoice->balance_due ?? max(0, $total - $currentPaid)), 2);

            $newPaid = round(max(0, $currentPaid - $refundAmount), 2);
            $newBalance = round(min($total, max(0, $currentBalance + $refundAmount)), 2);
            $newStatus = $newPaid <= 0.000001 ? 'refunded' : ($newBalance <= 0.000001 ? 'paid' : 'partially_paid');

            DB::table('invoices')
                ->where('id', $saleId)
                ->update($this->filterColumns('invoices', [
                    'status' => $newStatus,
                    'amount_paid' => $newPaid,
                    'balance_due' => $newBalance,
                    'paid_at' => $newStatus === 'paid' ? ($invoice->paid_at ?? $now) : null,
                    'updated_at' => $now,
                ]));

            $summary = $this->loadSaleSummary($businessId, $saleId);
            if (!$summary) {
                abort(404, 'Sale not found.');
            }

            return $summary;
        });

        return response()->json([
            'message' => 'Refund recorded successfully.',
            'sale' => $updated,
        ]);
    }

    public function void(Request $request, string $business, string $sale)
    {
        $businessId = $this->currentBusinessIdOrFail();
        $saleId = $this->parseSaleId($sale);

        $updated = DB::transaction(function () use ($businessId, $saleId) {
            $invoice = $this->findSale($businessId, $saleId, true);
            if (!$invoice) {
                abort(404, 'Sale not found.');
            }

            if ((string) ($invoice->status ?? '') === 'void') {
                $summary = $this->loadSaleSummary($businessId, $saleId);
                if (!$summary) {
                    abort(404, 'Sale not found.');
                }
                return $summary;
            }

            if ((float) ($invoice->amount_paid ?? 0) > 0.000001) {
                abort(422, 'Refund payments first before voiding this sale.');
            }

            if (Schema::hasTable('invoice_items')) {
                $itemRows = DB::table('invoice_items')
                    ->where('invoice_id', $saleId)
                    ->get();

                foreach ($itemRows as $item) {
                    $productId = (int) ($item->product_id ?? 0);
                    if ($productId <= 0) {
                        continue;
                    }

                    $product = DB::table('products')
                        ->where('business_id', $businessId)
                        ->where('id', $productId)
                        ->lockForUpdate()
                        ->first();

                    if (!$product || !(bool) ($product->track_inventory ?? false)) {
                        continue;
                    }

                    $qty = max(0, (float) ($item->quantity ?? 0));
                    if ($qty <= 0) {
                        continue;
                    }

                    $currentStock = (float) ($product->stock ?? $product->stock_quantity ?? 0);
                    $newStock = round($currentStock + $qty, 3);
                    $now = now();

                    DB::table('products')
                        ->where('id', $productId)
                        ->update($this->filterColumns('products', [
                            'stock' => $newStock,
                            'stock_quantity' => (int) floor($newStock),
                            'updated_at' => $now,
                        ]));

                    if (Schema::hasTable('stock_movements')) {
                        DB::table('stock_movements')->insert($this->filterColumns('stock_movements', [
                            'business_id' => $businessId,
                            'product_id' => $productId,
                            'direction' => 'in',
                            'reason' => 'pos_void',
                            'quantity' => $qty,
                            'unit_cost' => (float) ($item->unit_cost ?? 0),
                            'source_type' => 'Invoice',
                            'source_id' => $saleId,
                            'created_by' => auth()->id(),
                            'notes' => "POS void " . (string) ($invoice->number ?? $saleId),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]));
                    }
                }
            }

            $now = now();
            DB::table('invoices')
                ->where('id', $saleId)
                ->update($this->filterColumns('invoices', [
                    'status' => 'void',
                    'voided_at' => $now,
                    'voided_by' => auth()->id(),
                    'amount_paid' => 0,
                    'balance_due' => 0,
                    'paid_at' => null,
                    'updated_at' => $now,
                ]));

            $summary = $this->loadSaleSummary($businessId, $saleId);
            if (!$summary) {
                abort(404, 'Sale not found.');
            }

            return $summary;
        });

        return response()->json([
            'message' => 'Sale voided successfully.',
            'sale' => $updated,
        ]);
    }

    public function store(Request $request, string $business)
    {
        $businessId = $this->currentBusinessIdOrFail();

        $data = $request->validate([
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where(
                    fn ($q) => $q->where('business_id', $businessId)
                ),
            ],
            'note' => ['nullable', 'string'],
            'payment_method' => ['required', 'string', 'max:50'],
            'cash_received' => ['nullable', 'numeric', 'min:0'],
            'change_amount' => ['nullable', 'numeric', 'min:0'],
            'subtotal' => ['nullable', 'numeric', 'min:0'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'tax_total' => ['nullable', 'numeric', 'min:0'],
            'total' => ['nullable', 'numeric', 'min:0'],

            'payment' => ['nullable', 'array'],
            'payment.method' => ['nullable', 'string', 'max:50'],
            'payment.amount' => ['nullable', 'numeric', 'min:0'],
            'payment.cash_received' => ['nullable', 'numeric', 'min:0'],
            'payment.change_amount' => ['nullable', 'numeric', 'min:0'],
            'payment.reference' => ['nullable', 'string', 'max:190'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => [
                'nullable',
                'integer',
                Rule::exists('products', 'id')->where(
                    fn ($q) => $q->where('business_id', $businessId)
                ),
            ],
            'items.*.productId' => ['nullable', 'integer'],
            'items.*.qty' => ['nullable', 'numeric', 'min:0.001'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0.001'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'items.*.selling_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0'],
            'items.*.type' => ['nullable', Rule::in(['product', 'service'])],
            'items.*.name' => ['nullable', 'string', 'max:190'],
            'items.*.sku' => ['nullable', 'string', 'max:190'],
        ]);

        $normalizedItems = $this->normalizeItems($data['items']);
        $paymentInput = is_array($data['payment'] ?? null) ? $data['payment'] : [];

        $result = DB::transaction(function () use ($businessId, $data, $normalizedItems, $paymentInput) {
            $now = now();
            $receiptNumber = $this->nextDocumentNumber($businessId, 'sale', 'TKT-');
            $actorId = auth()->id();
            $actorName = auth()->user()?->name;
            $actorEmail = auth()->user()?->email;

            $invoiceId = DB::table('invoices')->insertGetId($this->filterColumns('invoices', [
                'business_id' => $businessId,
                'number' => $receiptNumber,
                'status' => 'issued',
                'customer_id' => $data['customer_id'] ?? null,
                'issue_date' => $now->toDateString(),
                'due_date' => $now->toDateString(),
                'currency' => 'USD',
                'exchange_rate' => 1,
                'title' => 'POS Sale',
                'payment_terms_days' => 0,
                'created_by' => $actorId,
                'notes' => $data['note'] ?? null,
                'subtotal' => 0,
                'tax_total' => 0,
                'total' => 0,
                'amount_paid' => 0,
                'balance_due' => 0,
                'source_document_type' => 'pos',
                'metadata' => json_encode([
                    'channel' => 'pos',
                    'requested_totals' => [
                        'subtotal' => (float) ($data['subtotal'] ?? 0),
                        'tax' => (float) ($data['tax_total'] ?? $data['tax'] ?? 0),
                        'total' => (float) ($data['total'] ?? 0),
                    ],
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ]));

            $subtotal = 0.0;
            $taxTotal = 0.0;
            $costTotal = 0.0;

            foreach ($normalizedItems as $line) {
                $product = null;
                if ($line['product_id']) {
                    $product = DB::table('products')
                        ->where('business_id', $businessId)
                        ->where('id', $line['product_id'])
                        ->lockForUpdate()
                        ->first();

                    if (!$product) {
                        throw ValidationException::withMessages([
                            "items.{$line['index']}.product_id" => 'Produit introuvable pour ce business.',
                        ]);
                    }
                }

                $lineType = $product
                    ? (($product->type ?? null) === 'service' ? 'service' : 'product')
                    : ($line['type'] ?: 'product');
                $lineName = $line['name'] ?: ($product->name ?? null);
                $lineSku = $line['sku'] ?: ($product->sku ?? null);

                if (!$lineName) {
                    throw ValidationException::withMessages([
                        "items.{$line['index']}.name" => 'Le nom du produit est requis.',
                    ]);
                }

                $unitCost = $product ? (float) ($product->cost_price ?? 0) : 0.0;

                $lineSubtotal = round($line['quantity'] * $line['unit_price'], 2);
                $lineTaxAmount = round($lineSubtotal * ($line['tax_rate'] / 100), 2);
                $lineTotal = round($lineSubtotal + $lineTaxAmount, 2);
                $lineCostTotal = round($unitCost * $line['quantity'], 2);

                DB::table('invoice_items')->insert($this->filterColumns('invoice_items', [
                    'business_id' => $businessId,
                    'invoice_id' => $invoiceId,
                    'product_id' => $line['product_id'],
                    'name' => $lineName,
                    'sku' => $lineSku,
                    'description' => null,
                    'quantity' => $line['quantity'],
                    'unit' => null,
                    'unit_price' => $line['unit_price'],
                    'unit_cost' => $unitCost,
                    'discount_type' => null,
                    'discount_value' => null,
                    'discount_amount' => 0,
                    'tax_rate' => $line['tax_rate'],
                    'tax_amount' => $lineTaxAmount,
                    'line_subtotal' => $lineSubtotal,
                    'line_total' => $lineTotal,
                    'line_cost_total' => $lineCostTotal,
                    'sort_order' => $line['index'] + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));

                $subtotal += $lineSubtotal;
                $taxTotal += $lineTaxAmount;
                $costTotal += $lineCostTotal;

                if (!$product) {
                    continue;
                }

                $trackInventory = (bool) ($product->track_inventory ?? false);
                if ($lineType !== 'product' || !$trackInventory) {
                    continue;
                }

                $currentStock = (float) ($product->stock ?? $product->stock_quantity ?? 0);
                if ($currentStock + 0.000001 < $line['quantity']) {
                    throw ValidationException::withMessages([
                        "items.{$line['index']}.qty" => "Stock insuffisant pour {$lineName}.",
                    ]);
                }

                $newStock = round(max(0, $currentStock - $line['quantity']), 3);

                DB::table('products')
                    ->where('id', $product->id)
                    ->update($this->filterColumns('products', [
                        'stock' => $newStock,
                        'stock_quantity' => (int) floor($newStock),
                        'updated_at' => $now,
                    ]));

                if (Schema::hasTable('stock_movements')) {
                    DB::table('stock_movements')->insert($this->filterColumns('stock_movements', [
                        'business_id' => $businessId,
                        'product_id' => $product->id,
                        'direction' => 'out',
                        'reason' => 'pos_sale',
                        'quantity' => $line['quantity'],
                        'unit_cost' => $unitCost,
                        'source_type' => 'Invoice',
                        'source_id' => $invoiceId,
                        'created_by' => $actorId,
                        'notes' => "POS sale {$receiptNumber}",
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]));
                }
            }

            $grandTotal = round($subtotal + $taxTotal, 2);
            if ($grandTotal <= 0) {
                throw ValidationException::withMessages([
                    'total' => 'Le total de la vente doit etre superieur a zero.',
                ]);
            }

            $requestedPayment = (float) ($paymentInput['amount'] ?? $data['total'] ?? $grandTotal);
            $amountPaid = round(min(max($requestedPayment, 0), $grandTotal), 2);
            if ($amountPaid <= 0) {
                throw ValidationException::withMessages([
                    'payment.amount' => 'Le montant du paiement doit etre superieur a zero.',
                ]);
            }

            $requestedMethod = (string) ($paymentInput['method'] ?? $data['payment_method'] ?? 'cash');
            $mappedMethod = $this->mapPaymentMethod($requestedMethod);
            $cashReceived = (float) ($paymentInput['cash_received'] ?? $data['cash_received'] ?? 0);
            $changeAmount = (float) ($paymentInput['change_amount'] ?? $data['change_amount'] ?? 0);

            if ($mappedMethod === 'cash' && $cashReceived + 0.000001 < $amountPaid) {
                throw ValidationException::withMessages([
                    'cash_received' => 'Montant recu insuffisant pour le paiement cash.',
                ]);
            }

            $balanceDue = round(max(0, $grandTotal - $amountPaid), 2);
            $status = $balanceDue <= 0.000001 ? 'paid' : 'partially_paid';
            $paidAt = $status === 'paid' ? $now : null;

            DB::table('invoice_payments')->insert($this->filterColumns('invoice_payments', [
                'business_id' => $businessId,
                'invoice_id' => $invoiceId,
                'kind' => 'payment',
                'method' => $mappedMethod,
                'amount' => $amountPaid,
                'currency' => 'USD',
                'exchange_rate' => 1,
                'paid_at' => $now,
                'reference' => $paymentInput['reference'] ?? null,
                'received_by' => $actorId,
                'notes' => $data['note'] ?? null,
                'metadata' => json_encode([
                    'channel' => 'pos',
                    'payment_method_requested' => $requestedMethod,
                    'cash_received' => $cashReceived,
                    'change_amount' => $changeAmount,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ]));

            DB::table('invoices')
                ->where('id', $invoiceId)
                ->update($this->filterColumns('invoices', [
                    'status' => $status,
                    'subtotal' => round($subtotal, 2),
                    'tax_total' => round($taxTotal, 2),
                    'total' => $grandTotal,
                    'amount_paid' => $amountPaid,
                    'balance_due' => $balanceDue,
                    'paid_at' => $paidAt,
                    'updated_at' => $now,
                    'metadata' => json_encode([
                        'channel' => 'pos',
                        'requested_totals' => [
                            'subtotal' => (float) ($data['subtotal'] ?? 0),
                            'tax' => (float) ($data['tax_total'] ?? $data['tax'] ?? 0),
                            'total' => (float) ($data['total'] ?? 0),
                        ],
                        'cogs_total' => round($costTotal, 2),
                    ]),
                ]));

            return [
                'id' => (string) $invoiceId,
                'sale_id' => (string) $invoiceId,
                'invoice_id' => (string) $invoiceId,
                'receipt_no' => $receiptNumber,
                'invoice_no' => $receiptNumber,
                'status' => $status,
                'created_at' => $now->toISOString(),
                'created_by' => $actorId ? (string) $actorId : null,
                'created_by_name' => $actorName ? (string) $actorName : null,
                'created_by_email' => $actorEmail ? (string) $actorEmail : null,
            ];
        });

        return response()->json([
            'message' => 'Sale completed successfully.',
            'sale' => $result,
        ], 201);
    }

    private function normalizeItems(array $items): array
    {
        $normalized = [];
        $errors = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $errors["items.{$index}"] = 'Ligne de vente invalide.';
                continue;
            }

            $quantity = $this->toFloat(Arr::get($item, 'qty', Arr::get($item, 'quantity')));
            $unitPrice = $this->toFloat(
                Arr::get($item, 'unit_price', Arr::get($item, 'price', Arr::get($item, 'selling_price')))
            );
            $taxRate = $this->toFloat(Arr::get($item, 'tax_rate', 0));

            if ($quantity <= 0) {
                $errors["items.{$index}.qty"] = 'Quantite invalide.';
            }

            if ($unitPrice < 0) {
                $errors["items.{$index}.unit_price"] = 'Prix unitaire invalide.';
            }

            $productId = Arr::get($item, 'product_id', Arr::get($item, 'productId'));
            $productId = is_numeric($productId) ? (int) $productId : null;

            $normalized[] = [
                'index' => $index,
                'product_id' => $productId,
                'name' => is_string($item['name'] ?? null) ? trim((string) $item['name']) : null,
                'sku' => is_string($item['sku'] ?? null) ? trim((string) $item['sku']) : null,
                'type' => in_array($item['type'] ?? null, ['product', 'service'], true)
                    ? (string) $item['type']
                    : null,
                'quantity' => round($quantity, 3),
                'unit_price' => round($unitPrice, 2),
                'tax_rate' => max(0, round($taxRate, 3)),
            ];
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    private function nextDocumentNumber(int $businessId, string $type, string $defaultPrefix): string
    {
        $now = now();

        DB::table('document_sequences')->insertOrIgnore([
            'business_id' => $businessId,
            'type' => $type,
            'prefix' => $defaultPrefix,
            'next_number' => 1,
            'padding' => 6,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $sequence = DB::table('document_sequences')
            ->where('business_id', $businessId)
            ->where('type', $type)
            ->lockForUpdate()
            ->first();

        if (!$sequence) {
            abort(500, 'Unable to initialize document sequence.');
        }

        $nextNumber = (int) $sequence->next_number;
        $padding = max(1, (int) ($sequence->padding ?? 6));
        $prefix = (string) ($sequence->prefix ?? $defaultPrefix);
        $formatted = $prefix . str_pad((string) $nextNumber, $padding, '0', STR_PAD_LEFT);

        DB::table('document_sequences')
            ->where('id', $sequence->id)
            ->update([
                'next_number' => $nextNumber + 1,
                'updated_at' => $now,
            ]);

        return $formatted;
    }

    private function mapPaymentMethod(string $method): string
    {
        $normalized = strtolower(trim(str_replace([' ', '-'], '_', $method)));

        return match ($normalized) {
            'cash', 'especes' => 'cash',
            'card', 'carte', 'credit_card', 'debit_card' => 'card',
            'bank', 'bank_transfer', 'transfer', 'virement' => 'bank',
            'mobile_money', 'mobile', 'momo', 'moncash' => 'moncash',
            'cheque', 'check' => 'cheque',
            default => 'other',
        };
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

    private function parseSaleId(string $sale): int
    {
        if (!ctype_digit($sale)) {
            abort(404, 'Sale not found.');
        }

        return (int) $sale;
    }

    private function salesQueryBase(int $businessId)
    {
        $query = DB::table('invoices')->select('invoices.*');

        if (Schema::hasTable('users')) {
            if ($this->hasColumn('invoices', 'created_by')) {
                $query
                    ->leftJoin('users as created_users', 'created_users.id', '=', 'invoices.created_by')
                    ->addSelect([
                        'created_users.name as created_by_name',
                        'created_users.email as created_by_email',
                    ]);
            }

            if ($this->hasColumn('invoices', 'voided_by')) {
                $query
                    ->leftJoin('users as voided_users', 'voided_users.id', '=', 'invoices.voided_by')
                    ->addSelect([
                        'voided_users.name as voided_by_name',
                        'voided_users.email as voided_by_email',
                    ]);
            }
        }

        if (Schema::hasTable('customers') && $this->hasColumn('invoices', 'customer_id')) {
            $query
                ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
                ->addSelect('customers.name as customer_name');
        }

        return $this->applyPosSaleScope($query, $businessId);
    }

    private function applyPosSaleScope($query, int $businessId)
    {
        $query->where('invoices.business_id', $businessId);

        $hasSourceDocumentType = $this->hasColumn('invoices', 'source_document_type');
        $hasTitle = $this->hasColumn('invoices', 'title');
        $hasNumber = $this->hasColumn('invoices', 'number');

        $query->where(function ($scope) use ($hasSourceDocumentType, $hasTitle, $hasNumber) {
            $has = false;

            if ($hasSourceDocumentType) {
                $scope->whereIn('invoices.source_document_type', ['pos', 'quote', 'proforma']);
                $has = true;
            }

            if ($hasTitle) {
                if ($has) {
                    $scope->orWhere('invoices.title', 'POS Sale');
                } else {
                    $scope->where('invoices.title', 'POS Sale');
                }
                $has = true;
            }

            if ($hasNumber) {
                if ($has) {
                    $scope->orWhere('invoices.number', 'like', 'TKT-%');
                } else {
                    $scope->where('invoices.number', 'like', 'TKT-%');
                }
                $has = true;
            }

            if (!$has) {
                $scope->whereRaw('1 = 0');
            }
        });

        return $query;
    }

    private function findSale(int $businessId, int $saleId, bool $lockForUpdate = false): ?object
    {
        $query = DB::table('invoices')->where('invoices.id', $saleId);
        $this->applyPosSaleScope($query, $businessId);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function loadSaleSummary(int $businessId, int $saleId): ?array
    {
        $row = $this->salesQueryBase($businessId)
            ->where('invoices.id', $saleId)
            ->first();

        if (!$row) {
            return null;
        }

        $aggregates = $this->loadSaleAggregates([$saleId]);

        return $this->mapSaleRow(
            $row,
            $aggregates['item_counts'][$saleId] ?? 0,
            $aggregates['payment_summaries'][$saleId] ?? $this->defaultPaymentSummary()
        );
    }

    private function loadSaleAggregates(array $invoiceIds): array
    {
        $itemCounts = [];
        $paymentSummaries = [];

        if (empty($invoiceIds)) {
            return [
                'item_counts' => $itemCounts,
                'payment_summaries' => $paymentSummaries,
            ];
        }

        if (Schema::hasTable('invoice_items')) {
            $itemRows = DB::table('invoice_items')
                ->select('invoice_id', DB::raw('COUNT(*) as lines_count'))
                ->whereIn('invoice_id', $invoiceIds)
                ->groupBy('invoice_id')
                ->get();

            foreach ($itemRows as $itemRow) {
                $itemCounts[(int) $itemRow->invoice_id] = (int) $itemRow->lines_count;
            }
        }

        if (Schema::hasTable('invoice_payments')) {
            $hasKind = $this->hasColumn('invoice_payments', 'kind');
            $paymentRows = DB::table('invoice_payments')
                ->whereIn('invoice_id', $invoiceIds)
                ->orderByDesc('id')
                ->get();

            foreach ($paymentRows as $paymentRow) {
                $invoiceId = (int) $paymentRow->invoice_id;
                if (!isset($paymentSummaries[$invoiceId])) {
                    $paymentSummaries[$invoiceId] = $this->defaultPaymentSummary();
                }

                $kind = $hasKind ? strtolower((string) ($paymentRow->kind ?? 'payment')) : 'payment';
                $amount = round((float) ($paymentRow->amount ?? 0), 2);

                if ($kind === 'refund') {
                    $paymentSummaries[$invoiceId]['refunds'] += $amount;
                    continue;
                }

                $paymentSummaries[$invoiceId]['payments'] += $amount;
                if (!$paymentSummaries[$invoiceId]['method']) {
                    $paymentSummaries[$invoiceId]['method'] = (string) ($paymentRow->method ?? '');
                }
            }
        }

        return [
            'item_counts' => $itemCounts,
            'payment_summaries' => $paymentSummaries,
        ];
    }

    private function mapSaleRow(object $row, int $itemsCount, array $paymentSummary): array
    {
        $summary = array_merge($this->defaultPaymentSummary(), $paymentSummary);

        $total = round((float) ($row->total ?? 0), 2);
        $rowAmountPaid = round((float) ($row->amount_paid ?? 0), 2);
        $netFromPayments = round(max(0, $summary['payments'] - $summary['refunds']), 2);
        $amountPaid = max($rowAmountPaid, $netFromPayments);

        $rowBalance = round((float) ($row->balance_due ?? max(0, $total - $amountPaid)), 2);
        $balanceDue = max(0, $rowBalance);

        $status = (string) ($row->status ?? '');
        if ($status === '') {
            if ($amountPaid <= 0) {
                $status = 'issued';
            } elseif ($balanceDue <= 0.000001) {
                $status = 'paid';
            } else {
                $status = 'partially_paid';
            }
        }

        return [
            'id' => (string) $row->id,
            'receipt_no' => (string) ($row->number ?? ('TKT-' . $row->id)),
            'status' => $status,
            'created_at' => (string) ($row->created_at ?? $row->issue_date ?? now()->toISOString()),
            'issue_date' => $row->issue_date ? (string) $row->issue_date : null,
            'created_by' => isset($row->created_by) ? (string) $row->created_by : null,
            'created_by_name' => isset($row->created_by_name) && $row->created_by_name
                ? (string) $row->created_by_name
                : null,
            'created_by_email' => isset($row->created_by_email) && $row->created_by_email
                ? (string) $row->created_by_email
                : null,
            'cashier_id' => isset($row->created_by) ? (string) $row->created_by : null,
            'cashier_name' => isset($row->created_by_name) && $row->created_by_name
                ? (string) $row->created_by_name
                : null,
            'voided_at' => $row->voided_at ? (string) $row->voided_at : null,
            'voided_by' => isset($row->voided_by) ? (string) $row->voided_by : null,
            'voided_by_name' => isset($row->voided_by_name) && $row->voided_by_name
                ? (string) $row->voided_by_name
                : null,
            'voided_by_email' => isset($row->voided_by_email) && $row->voided_by_email
                ? (string) $row->voided_by_email
                : null,
            'customer_name' => isset($row->customer_name) && $row->customer_name
                ? (string) $row->customer_name
                : 'Client comptoir',
            'items_count' => $itemsCount,
            'total' => $total,
            'amount_paid' => $amountPaid,
            'balance_due' => $balanceDue,
            'payment_method' => $summary['method'] ?: null,
            'paid_total' => round($summary['payments'], 2),
            'refunded_total' => round($summary['refunds'], 2),
            'notes' => $row->notes ? (string) $row->notes : null,
            'can_refund' => $status !== 'void' && $amountPaid > 0.000001,
            'can_void' => $status !== 'void' && $amountPaid <= 0.000001,
        ];
    }

    private function defaultPaymentSummary(): array
    {
        return [
            'method' => null,
            'payments' => 0.0,
            'refunds' => 0.0,
        ];
    }

    private function currentBusinessIdOrFail(): int
    {
        $currentBusiness = app()->bound('currentBusiness') ? app('currentBusiness') : null;
        if (!$currentBusiness) {
            abort(403, 'Business context is required.');
        }

        return (int) $currentBusiness->id;
    }
}
