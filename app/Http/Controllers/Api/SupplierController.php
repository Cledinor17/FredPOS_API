<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class SupplierController extends Controller
{
    private ?bool $hasBusinessColumn = null;
    private ?bool $hasContactPersonColumn = null;
    private ?bool $hasAddressColumn = null;

    private function currentBusinessOrFail()
    {
        $currentBusiness = app()->bound('currentBusiness') ? app('currentBusiness') : null;
        if (!$currentBusiness) {
            abort(403, 'Business context is required.');
        }

        return $currentBusiness;
    }

    private function hasBusinessColumn(): bool
    {
        if ($this->hasBusinessColumn === null) {
            $this->hasBusinessColumn = Schema::hasColumn('suppliers', 'business_id');
        }

        return $this->hasBusinessColumn;
    }

    private function hasContactPersonColumn(): bool
    {
        if ($this->hasContactPersonColumn === null) {
            $this->hasContactPersonColumn = Schema::hasColumn('suppliers', 'contact_person');
        }

        return $this->hasContactPersonColumn;
    }

    private function hasAddressColumn(): bool
    {
        if ($this->hasAddressColumn === null) {
            $this->hasAddressColumn = Schema::hasColumn('suppliers', 'address');
        }

        return $this->hasAddressColumn;
    }

    private function currentBusinessIdOrFail(): int
    {
        return (int) $this->currentBusinessOrFail()->id;
    }

    private function serializeSupplier(Supplier $supplier): array
    {
        $currentBusiness = $this->currentBusinessOrFail();
        $payload = $supplier->toArray();

        $payload['business_id'] = (int) ($payload['business_id'] ?? $currentBusiness->id);
        $payload['business'] = [
            'id' => (int) $currentBusiness->id,
            'name' => (string) ($currentBusiness->name ?? ''),
            'slug' => (string) ($currentBusiness->slug ?? ''),
        ];

        return $payload;
    }

    private function scopedQuery(int $businessId)
    {
        $query = Supplier::query();

        if ($this->hasBusinessColumn()) {
            $query->where('business_id', $businessId);
        }

        return $query;
    }

    private function resolveSupplierOrFail(int $businessId, string $supplier): Supplier
    {
        if (!ctype_digit($supplier)) {
            abort(404, 'Supplier not found.');
        }

        $resolved = $this->scopedQuery($businessId)->whereKey((int) $supplier)->first();
        if (!$resolved) {
            abort(404, 'Supplier not found.');
        }

        return $resolved;
    }

    public function index(Request $request)
    {
        $businessId = $this->currentBusinessIdOrFail();
        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min((int) $request->query('per_page', 20), 100));

        $query = $this->scopedQuery($businessId);

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $query->where(function ($sub) use ($search) {
                $sub->where('name', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%')
                    ->orWhere('department', 'like', '%' . $search . '%');

                if ($this->hasContactPersonColumn()) {
                    $sub->orWhere('contact_person', 'like', '%' . $search . '%');
                }

                if ($this->hasAddressColumn()) {
                    $sub->orWhere('address', 'like', '%' . $search . '%');
                }
            });
        }

        $total = (clone $query)->count();
        $rows = $query
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));

        return response()->json([
            'data' => $rows->map(fn (Supplier $supplier) => $this->serializeSupplier($supplier))->values(),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $businessId = $this->currentBusinessIdOrFail();

        $data = $request->validate([
            'department' => ['nullable', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:190'],
            'contact_person' => ['nullable', 'string', 'max:190'],
            'phone' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:255'],
            'balance' => ['nullable', 'numeric', 'min:0'],
        ]);

        if ($this->hasBusinessColumn()) {
            $data['business_id'] = $businessId;
        }
        if (!$this->hasContactPersonColumn()) {
            unset($data['contact_person']);
        }
        if (!$this->hasAddressColumn()) {
            unset($data['address']);
        }

        if (!array_key_exists('department', $data) || trim((string) $data['department']) === '') {
            $data['department'] = 'General';
        }

        if (!array_key_exists('balance', $data)) {
            $data['balance'] = 0;
        }

        $supplier = Supplier::create($data);

        return response()->json($this->serializeSupplier($supplier), 201);
    }

    public function show(string $business, string $supplier)
    {
        $businessId = $this->currentBusinessIdOrFail();
        $resolved = $this->resolveSupplierOrFail($businessId, $supplier);

        return response()->json($this->serializeSupplier($resolved));
    }

    public function update(Request $request, string $business, string $supplier)
    {
        $businessId = $this->currentBusinessIdOrFail();
        $resolved = $this->resolveSupplierOrFail($businessId, $supplier);

        $data = $request->validate([
            'department' => ['sometimes', 'nullable', 'string', 'max:100'],
            'name' => ['sometimes', 'required', 'string', 'max:190'],
            'contact_person' => ['sometimes', 'nullable', 'string', 'max:190'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:60'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'balance' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);

        if ($this->hasBusinessColumn()) {
            $data['business_id'] = $businessId;
        }
        if (!$this->hasContactPersonColumn()) {
            unset($data['contact_person']);
        }
        if (!$this->hasAddressColumn()) {
            unset($data['address']);
        }

        $resolved->update($data);

        return response()->json($this->serializeSupplier($resolved->fresh()));
    }

    public function destroy(string $business, string $supplier)
    {
        $businessId = $this->currentBusinessIdOrFail();
        $resolved = $this->resolveSupplierOrFail($businessId, $supplier);
        $resolved->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
