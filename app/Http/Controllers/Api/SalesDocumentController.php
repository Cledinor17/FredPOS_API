<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\{SalesDocument, Invoice, DocumentSequence, Product};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SalesDocumentController extends Controller
{
 private function currentBusinessIdOrFail(): int
    {
        $currentBusiness = app()->bound('currentBusiness') ? app('currentBusiness') : null;
        if (!$currentBusiness) {
            abort(403, 'Business context is required.');
        }

        return (int) $currentBusiness->id;
    }

    private function resolveDocumentOrFail(int $businessId, mixed $document): SalesDocument
    {
        if ($document instanceof SalesDocument) {
            $resolved = SalesDocument::query()
                ->where('business_id', $businessId)
                ->whereKey((int) $document->getKey())
                ->first();

            if ($resolved) {
                return $resolved;
            }
        }

        if (is_int($document) || (is_string($document) && ctype_digit($document))) {
            $resolved = SalesDocument::query()
                ->where('business_id', $businessId)
                ->whereKey((int) $document)
                ->first();

            if ($resolved) {
                return $resolved;
            }
        }

        abort(404, 'Document not found.');
    }

 public function index(Request $request)
    {
        $q = SalesDocument::query()
            ->with([
                'customer',
                'creator:id,name,email',
                'convertedInvoice:id,number,status,amount_paid,balance_due,total,currency',
            ])
            ->withCount('items');

        if ($request->filled('type'))   $q->where('type', $request->type);
        if ($request->filled('status')) $q->where('status', $request->status);
        if ($request->filled('from'))   $q->whereDate('issue_date', '>=', $request->from);
        if ($request->filled('to'))     $q->whereDate('issue_date', '<=', $request->to);

        return $q->orderByDesc('id')->paginate(20);
    }

    public function show(SalesDocument $document)
    {
        return $document->load([
            'customer',
            'creator:id,name,email',
            'convertedInvoice:id,number,status,amount_paid,balance_due,total,currency',
            'items',
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateDoc($request, false);

        return DB::transaction(function () use ($data) {
            $number = $this->nextNumber($data['type']); // quote/proforma

            $doc = SalesDocument::create(array_merge($data, [
                'number' => $number,
                'status' => $data['status'] ?? 'draft',
                'created_by' => auth()->id(),
            ]));

            $this->syncItemsAndTotals($doc, $data['items']);

            return $doc->load([
                'creator:id,name,email',
                'convertedInvoice:id,number,status,amount_paid,balance_due,total,currency',
                'items',
            ]);
        });
    }

    public function update(Request $request, SalesDocument $document)
    {
        $data = $this->validateDoc($request, true);

        return DB::transaction(function () use ($document, $data) {
            // Option stricte (recommandée) : on ne change pas type/number
            unset($data['type'], $data['number']);

            $document->update($data);

            if (isset($data['items'])) {
                $this->syncItemsAndTotals($document, $data['items']);
            } else {
                $this->recalculateTotals($document);
            }

            return $document->load([
                'creator:id,name,email',
                'convertedInvoice:id,number,status,amount_paid,balance_due,total,currency',
                'items',
            ]);
        });
    }

    public function destroy(SalesDocument $document)
    {
        $document->delete();
        return response()->json(['message' => 'Deleted']);
    }

    // --- actions workflow rapides (optionnelles mais très utiles) ---
    public function markSent(SalesDocument $document)
    {
        $document->update(['status' => 'sent', 'sent_at' => now()]);
        return $document;
    }

    public function accept(SalesDocument $document)
    {
        $document->update(['status' => 'accepted', 'accepted_at' => now()]);
        return $document;
    }

    public function reject(SalesDocument $document)
    {
        $document->update(['status' => 'rejected']);
        return $document;
    }

    public function cancel(SalesDocument $document)
    {
        $document->update(['status' => 'cancelled']);
        return $document;
    }

    private function validateDoc(Request $request, bool $isUpdate): array
    {
        return $request->validate([
            'type' => [$isUpdate ? 'sometimes' : 'required', Rule::in(['quote','proforma'])],
            'status' => ['sometimes', Rule::in(['draft','sent','accepted','rejected','expired','converted','cancelled'])],

            'customer_id' => ['nullable','integer','exists:customers,id'],
            'issue_date' => ['nullable','date'],
            'expiry_date' => ['nullable','date'],

            'currency' => ['sometimes','string','max:10'],
            'exchange_rate' => ['sometimes','numeric','min:0.000001'],

            'reference' => ['nullable','string','max:190'],
            'title' => ['nullable','string','max:190'],
            'payment_terms_days' => ['nullable','integer','min:0'],

            'salesperson_id' => ['nullable','integer','exists:users,id'],

            'billing_address' => ['nullable','array'],
            'shipping_address' => ['nullable','array'],

            'shipping_method' => ['nullable','string','max:190'],
            'shipping_cost' => ['sometimes','numeric','min:0'],

            'discount_type' => ['nullable', Rule::in(['percent','fixed'])],
            'discount_value' => ['nullable','numeric','min:0'],
            'is_tax_inclusive' => ['sometimes','boolean'],

            'notes' => ['nullable','string'],
            'terms' => ['nullable','string'],
            'internal_notes' => ['nullable','string'],
            'metadata' => ['nullable','array'],

            'items' => [$isUpdate ? 'sometimes' : 'required','array','min:1'],
            'items.*.product_id' => ['nullable','integer'],
            'items.*.name' => ['required','string','max:190'],
            'items.*.sku' => ['nullable','string','max:190'],
            'items.*.description' => ['nullable','string'],
            'items.*.quantity' => ['required','numeric','min:0.001'],
            'items.*.unit' => ['nullable','string','max:30'],
            'items.*.unit_price' => ['required','numeric','min:0'],
            'items.*.discount_type' => ['nullable', Rule::in(['percent','fixed'])],
            'items.*.discount_value' => ['nullable','numeric','min:0'],
            'items.*.tax_rate' => ['nullable','numeric','min:0'],
            'items.*.sort_order' => ['nullable','integer','min:1'],
        ]);
    }

    private function nextNumber(string $type): string
    {
        // Concurrency safe: lock row
        $seq = DocumentSequence::where('type', $type)->lockForUpdate()->first();

        if (!$seq) {
            $seq = DocumentSequence::create([
                'type' => $type,
                'prefix' => $type === 'quote' ? 'DV-' : 'PF-',
                'next_number' => 1,
                'padding' => 6,
            ]);
        }

        $num = str_pad((string)$seq->next_number, $seq->padding, '0', STR_PAD_LEFT);
        $seq->increment('next_number');

        return $seq->prefix.$num;
    }

    private function syncItemsAndTotals(SalesDocument $doc, array $items): void
    {
        $doc->items()->delete();

        $subtotal = 0.0;
        $taxTotal = 0.0;

        $order = 1;
        foreach ($items as $it) {
            $qty = (float)$it['quantity'];
            $unitPrice = (float)$it['unit_price'];
            $lineBase = $qty * $unitPrice;

            $discAmount = 0.0;
            if (!empty($it['discount_type']) && isset($it['discount_value'])) {
                $dv = (float)$it['discount_value'];
                $discAmount = $it['discount_type'] === 'percent'
                    ? ($lineBase * $dv / 100.0)
                    : $dv;
            }

            $lineAfterDiscount = max(0.0, $lineBase - $discAmount);

            $taxRate = isset($it['tax_rate']) ? (float)$it['tax_rate'] : 0.0;
            $taxAmount = $lineAfterDiscount * $taxRate / 100.0;

            $lineSubtotal = $lineAfterDiscount;
            $lineTotal = $lineSubtotal + $taxAmount;

            $doc->items()->create([
                'product_id' => $it['product_id'] ?? null,
                'name' => $it['name'],
                'sku' => $it['sku'] ?? null,
                'description' => $it['description'] ?? null,
                'quantity' => $qty,
                'unit' => $it['unit'] ?? null,
                'unit_price' => $unitPrice,
                'discount_type' => $it['discount_type'] ?? null,
                'discount_value' => $it['discount_value'] ?? null,
                'discount_amount' => $discAmount,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'line_subtotal' => $lineSubtotal,
                'line_total' => $lineTotal,
                'sort_order' => $it['sort_order'] ?? $order++,
            ]);

            $subtotal += $lineSubtotal;
            $taxTotal += $taxAmount;
        }

        // discount global
        $globalDisc = 0.0;
        if (!empty($doc->discount_type) && !is_null($doc->discount_value)) {
            $dv = (float)$doc->discount_value;
            $globalDisc = $doc->discount_type === 'percent'
                ? ($subtotal * $dv / 100.0)
                : $dv;
            $globalDisc = min($subtotal, $globalDisc);
        }

        $subtotalAfterGlobal = max(0.0, $subtotal - $globalDisc);
        $total = $subtotalAfterGlobal + $taxTotal + (float)$doc->shipping_cost;

        $doc->update([
            'discount_amount' => $globalDisc,
            'subtotal' => $subtotalAfterGlobal,
            'tax_total' => $taxTotal,
            'total' => $total,
        ]);
    }

    private function recalculateTotals(SalesDocument $doc): void
    {
        // recalcul à partir des items existants
        $items = $doc->items()->get()->map(fn($i) => $i->toArray())->toArray();
        $this->syncItemsAndTotals($doc, $items);
    }

//     // app/Http/Controllers/Api/SalesDocumentController.php
// use App\Models\{SalesDocument, Invoice, InvoiceItem, DocumentSequence};
// use Illuminate\Support\Facades\DB;

public function convertToInvoice(Request $request, string $business, $document)
{
    $businessId = $this->currentBusinessIdOrFail();
    $resolvedDocument = $this->resolveDocumentOrFail($businessId, $document);
    $options = $this->validateConvertOptions($request);

    return DB::transaction(function () use ($resolvedDocument, $businessId, $options) {
        $document = SalesDocument::where('business_id', $businessId)
            ->where('id', $resolvedDocument->id)
            ->lockForUpdate()
            ->firstOrFail();

        if (!empty($document->converted_invoice_id)) {
            $existingInvoice = Invoice::query()
                ->where('business_id', $businessId)
                ->whereKey((int) $document->converted_invoice_id)
                ->first();

            if ($existingInvoice) {
                // Ensure stock movement exists for legacy converted documents.
                app(\App\Services\StockService::class)->issueInvoiceStock($existingInvoice);
                abort(422, 'Document deja converti en facture ' . $existingInvoice->number . '.');
            }

            // Stale link (invoice removed), allow a fresh conversion.
            $document->update(['converted_invoice_id' => null]);
        }

        if (!in_array($document->status, ['accepted', 'sent', 'draft'], true)) {
            abort(422, 'Document not convertible in current status.');
        }

        $invoiceNumber = $this->nextNumberForInvoice();
        $issueDate = $document->issue_date ?? now()->toDateString();
        $terms = $document->payment_terms_days ?? 0;
        $dueDate = \Carbon\Carbon::parse($issueDate)->addDays($terms)->toDateString();
        $shippingCost = (float) ($document->shipping_cost ?? 0);

        $documentItems = $document->items()->orderBy('sort_order')->get();
        if ($documentItems->isEmpty()) {
            abort(422, 'Document has no items to convert.');
        }

        $lineSubtotalTotal = 0.0;
        $lineTaxTotal = 0.0;
        $hasAnyItem = false;
        $costByProductId = [];
        $requiredByProductId = [];

        foreach ($documentItems as $it) {
            $qty = (float) ($it->quantity ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $hasAnyItem = true;
            $lineSubtotalTotal += (float) ($it->line_subtotal ?? 0);
            $lineTaxTotal += (float) ($it->tax_amount ?? 0);

            if (!$it->product_id) {
                continue;
            }

            $product = Product::query()
                ->where('business_id', $businessId)
                ->whereKey((int) $it->product_id)
                ->lockForUpdate()
                ->first();

            if (!$product) {
                continue;
            }

            $costByProductId[(int) $product->id] = (float) ($product->cost_price ?? 0);

            if (!(bool) ($product->track_inventory ?? false)) {
                continue;
            }

            $productId = (int) $product->id;
            $requiredByProductId[$productId] = ($requiredByProductId[$productId] ?? 0) + $qty;
        }

        foreach ($requiredByProductId as $productId => $requiredQty) {
            $product = Product::query()
                ->where('business_id', $businessId)
                ->whereKey($productId)
                ->lockForUpdate()
                ->first();

            if (!$product || !(bool) ($product->track_inventory ?? false)) {
                continue;
            }

            $available = (float) ($product->stock ?? $product->stock_quantity ?? 0);
            if ($available + 0.000001 < $requiredQty) {
                abort(422, 'Stock insuffisant pour le produit: ' . ($product->name ?? ('#' . $product->id)));
            }
        }

        if (!$hasAnyItem) {
            abort(422, 'Document has no valid quantity to convert.');
        }

        $discountType = $options['discount_type'] ?? ($document->discount_type ?: null);
        $discountValueRaw = array_key_exists('discount_value', $options)
            ? (float) ($options['discount_value'] ?? 0)
            : (float) ($document->discount_value ?? 0);

        $discountAmount = $this->computeGlobalDiscountAmount($lineSubtotalTotal, $discountType, $discountValueRaw);
        $subtotalAfterDiscount = max(0, $lineSubtotalTotal - $discountAmount);
        $invoiceTotal = round($subtotalAfterDiscount + $lineTaxTotal + $shippingCost, 2);

        if ($invoiceTotal <= 0) {
            abort(422, 'Invoice total must be greater than zero.');
        }

        $invoice = Invoice::create([
            'number' => $invoiceNumber,
            'status' => 'issued',

            'customer_id' => $document->customer_id,
            'issue_date' => $issueDate,
            'due_date' => $dueDate,

            'currency' => $document->currency,
            'exchange_rate' => $document->exchange_rate,

            'reference' => $document->reference,
            'title' => $document->type === 'quote' ? 'Facture (depuis devis)' : 'Facture (depuis proforma)',
            'payment_terms_days' => $document->payment_terms_days,

            'salesperson_id' => $document->salesperson_id,
            'created_by' => auth()->id(),

            'billing_address' => $document->billing_address,
            'shipping_address' => $document->shipping_address,

            'shipping_method' => $document->shipping_method,
            'shipping_cost' => $shippingCost,

            'discount_type' => $discountType,
            'discount_value' => $discountType ? $discountValueRaw : null,
            'discount_amount' => $discountAmount,

            'is_tax_inclusive' => $document->is_tax_inclusive,

            'subtotal' => round($subtotalAfterDiscount, 2),
            'tax_total' => round($lineTaxTotal, 2),
            'total' => $invoiceTotal,

            'amount_paid' => 0,
            'balance_due' => $invoiceTotal,

            'notes' => $document->notes,
            'terms' => $document->terms,
            'internal_notes' => $document->internal_notes,

            'source_document_id' => $document->id,
            'source_document_type' => $document->type,

            'metadata' => array_merge(
                is_array($document->metadata) ? $document->metadata : [],
                ['converted_from_document' => true]
            ),
        ]);

        foreach ($documentItems as $it) {
            $unitCost = $it->product_id ? ($costByProductId[(int) $it->product_id] ?? 0.0) : 0.0;
            $lineCost = (float) $it->quantity * $unitCost;

            $invoice->items()->create([
                'product_id' => $it->product_id,
                'name' => $it->name,
                'sku' => $it->sku,
                'description' => $it->description,
                'quantity' => $it->quantity,
                'unit' => $it->unit,
                'unit_price' => $it->unit_price,
                'discount_type' => $it->discount_type,
                'discount_value' => $it->discount_value,
                'discount_amount' => $it->discount_amount,
                'tax_rate' => $it->tax_rate,
                'tax_amount' => $it->tax_amount,
                'line_subtotal' => $it->line_subtotal,
                'line_total' => $it->line_total,
                'sort_order' => $it->sort_order,
                'unit_cost' => $unitCost,
                'line_cost_total' => $lineCost,
            ]);
        }

        app(\App\Services\StockService::class)->issueInvoiceStock($invoice);
        app(\App\Services\LedgerService::class)->postInvoiceIssued($invoice);
        app(\App\Services\LedgerService::class)->postInvoiceCogs($invoice);

        $payment = $this->createInitialPaymentIfAny($invoice, $options);

        $document->update([
            'status' => 'converted',
            'converted_invoice_id' => $invoice->id,
        ]);

        $audit = app(\App\Services\AuditService::class);

        $audit->log(
            'document.convert_to_invoice',
            $document,
            ['status_before' => $document->getOriginal('status')],
            ['status_after' => $document->status, 'converted_invoice_id' => $document->converted_invoice_id],
            ['invoice_number' => $invoice->number]
        );

        $audit->log('invoice.created_from_document', $invoice, null, $audit->snapshot($invoice), [
            'source_document_id' => $document->id,
            'source_type' => $document->type,
            'discount_type' => $discountType,
            'discount_value' => $discountType ? $discountValueRaw : null,
            'discount_amount' => round($discountAmount, 2),
            'payment_amount' => $payment ? (float) $payment->amount : 0,
        ]);

        return $invoice->load(['creator:id,name,email', 'items', 'payments']);
    });
}

private function validateConvertOptions(Request $request): array
{
    $data = $request->validate([
        'discount_type' => ['nullable', Rule::in(['percent', 'fixed'])],
        'discount_value' => ['nullable', 'numeric', 'min:0'],
        'payment' => ['nullable', 'array'],
        'payment.amount' => ['nullable', 'numeric', 'min:0.01'],
        'payment.method' => ['nullable', Rule::in(['cash', 'card', 'bank', 'moncash', 'cheque', 'other'])],
        'payment.paid_at' => ['nullable', 'date'],
        'payment.reference' => ['nullable', 'string', 'max:190'],
        'payment.notes' => ['nullable', 'string'],
    ]);

    $hasDiscountType = array_key_exists('discount_type', $data) && !empty($data['discount_type']);
    $hasDiscountValue = array_key_exists('discount_value', $data) && !is_null($data['discount_value']);
    if ($hasDiscountType xor $hasDiscountValue) {
        abort(422, 'Provide discount_type and discount_value together.');
    }

    return $data;
}

private function computeGlobalDiscountAmount(float $baseSubtotal, ?string $discountType, float $discountValue): float
{
    if (!$discountType || $discountValue <= 0 || $baseSubtotal <= 0) {
        return 0.0;
    }

    $amount = $discountType === 'percent'
        ? ($baseSubtotal * $discountValue / 100.0)
        : $discountValue;

    return round(min($baseSubtotal, max(0, $amount)), 2);
}

private function createInitialPaymentIfAny(Invoice $invoice, array $options): ?\App\Models\InvoicePayment
{
    $paymentData = is_array($options['payment'] ?? null) ? $options['payment'] : [];
    $amount = isset($paymentData['amount']) ? round((float) $paymentData['amount'], 2) : 0.0;

    if ($amount <= 0) {
        return null;
    }

    $invoiceTotal = round((float) $invoice->total, 2);
    if ($amount - $invoiceTotal > 0.000001) {
        abort(422, 'Initial payment exceeds invoice total.');
    }

    $payment = $invoice->payments()->create([
        'kind' => 'payment',
        'method' => $paymentData['method'] ?? 'cash',
        'amount' => $amount,
        'currency' => $invoice->currency,
        'exchange_rate' => $invoice->exchange_rate,
        'paid_at' => $paymentData['paid_at'] ?? now(),
        'reference' => $paymentData['reference'] ?? null,
        'received_by' => auth()->id(),
        'notes' => $paymentData['notes'] ?? null,
        'metadata' => ['source' => 'document_conversion'],
    ]);

    app(\App\Services\LedgerService::class)->postInvoicePayment($invoice, $payment);

    $newPaid = round((float) $invoice->amount_paid + $amount, 2);
    $newBalance = round(max(0, (float) $invoice->total - $newPaid), 2);
    $newStatus = $newBalance <= 0.000001 ? 'paid' : 'partially_paid';

    $invoice->update([
        'amount_paid' => $newPaid,
        'balance_due' => $newBalance,
        'status' => $newStatus,
        'paid_at' => $newStatus === 'paid' ? ($payment->paid_at ?? now()) : null,
    ]);

    return $payment;
}

private function nextNumberForInvoice(): string
{
    $seq = DocumentSequence::where('type', 'invoice')->lockForUpdate()->first();

    if (!$seq) {
        $seq = DocumentSequence::create([
            'type' => 'invoice',
            'prefix' => 'FA-',
            'next_number' => 1,
            'padding' => 6,
        ]);
    }

    $num = str_pad((string)$seq->next_number, $seq->padding, '0', STR_PAD_LEFT);
    $seq->increment('next_number');

    return $seq->prefix.$num;
}

}






