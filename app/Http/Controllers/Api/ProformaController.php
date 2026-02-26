<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Proforma, ProformaItem, DocumentSequence};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProformaController extends Controller
{
    public function index(Request $request)
    {
        $q = Proforma::query()->with(['customer'])->withCount('items');

        if ($request->filled('status')) $q->where('status', $request->status);
        if ($request->filled('from')) $q->whereDate('issue_date', '>=', $request->from);
        if ($request->filled('to')) $q->whereDate('issue_date', '<=', $request->to);

        return $q->orderByDesc('id')->paginate(20);
    }

    public function show(Proforma $proforma)
    {
        return $proforma->load(['customer','items']);
    }

    public function store(Request $request)
    {
        $data = $this->validateProforma($request, isUpdate:false);

        return DB::transaction(function () use ($data) {
            $number = $this->nextNumber('proforma');

            $proforma = Proforma::create(array_merge($data, [
                'number' => $number,
                'status' => $data['status'] ?? 'draft',
                'created_by' => auth()->id(),
            ]));

            $this->syncItemsAndTotals($proforma, $data['items'] ?? []);

            return $proforma->load('items');
        });
    }

    public function update(Request $request, Proforma $proforma)
    {
        // bloque modification si déjà converti/cancel ? à toi de décider
        $data = $this->validateProforma($request, isUpdate:true);

        return DB::transaction(function () use ($proforma, $data) {
            $proforma->update($data);
            if (isset($data['items'])) {
                $this->syncItemsAndTotals($proforma, $data['items']);
            } else {
                $this->recalculateTotals($proforma);
            }
            return $proforma->load('items');
        });
    }

    public function destroy(Proforma $proforma)
    {
        $proforma->delete();
        return response()->json(['message' => 'Deleted']);
    }

    private function validateProforma(Request $request, bool $isUpdate): array
    {
        return $request->validate([
            'customer_id' => ['nullable','integer','exists:customers,id'],
            'status' => ['sometimes', Rule::in(['draft','sent','accepted','rejected','expired','converted','cancelled'])],

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

            // Items (maximum utile)
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
        $seq = DocumentSequence::firstOrCreate(
            ['type' => $type],
            ['prefix' => 'PF-', 'next_number' => 1, 'padding' => 6]
        );

        $num = str_pad((string)$seq->next_number, $seq->padding, '0', STR_PAD_LEFT);
        $seq->increment('next_number');

        return $seq->prefix . $num;
    }

    private function syncItemsAndTotals(Proforma $proforma, array $items): void
    {
        // reset items (simple et robuste pour V1)
        $proforma->items()->delete();

        $subtotal = 0;
        $taxTotal = 0;

        $order = 1;
        foreach ($items as $it) {
            $qty = (float)$it['quantity'];
            $unitPrice = (float)$it['unit_price'];

            $lineBase = $qty * $unitPrice;

            // discount ligne
            $discAmount = 0;
            if (!empty($it['discount_type']) && isset($it['discount_value'])) {
                $dv = (float)$it['discount_value'];
                $discAmount = $it['discount_type'] === 'percent'
                    ? ($lineBase * $dv / 100)
                    : $dv;
            }

            $lineAfterDiscount = max(0, $lineBase - $discAmount);

            $taxRate = isset($it['tax_rate']) ? (float)$it['tax_rate'] : 0;
            $taxAmount = $lineAfterDiscount * $taxRate / 100;

            $lineSubtotal = $lineAfterDiscount;
            $lineTotal = $lineSubtotal + $taxAmount;

            $proforma->items()->create([
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
        $globalDisc = 0;
        if (!empty($proforma->discount_type) && !is_null($proforma->discount_value)) {
            $dv = (float)$proforma->discount_value;
            $globalDisc = $proforma->discount_type === 'percent'
                ? ($subtotal * $dv / 100)
                : $dv;
            $globalDisc = min($subtotal, $globalDisc);
        }

        $subtotalAfterGlobal = max(0, $subtotal - $globalDisc);
        $total = $subtotalAfterGlobal + $taxTotal + (float)$proforma->shipping_cost;

        $proforma->update([
            'discount_amount' => $globalDisc,
            'subtotal' => $subtotalAfterGlobal,
            'tax_total' => $taxTotal,
            'total' => $total,
        ]);
    }

    private function recalculateTotals(Proforma $proforma): void
    {
        $items = $proforma->items()->get()->toArray();
        $this->syncItemsAndTotals($proforma, $items);
    }
}
