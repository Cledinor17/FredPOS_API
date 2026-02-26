<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    private function currentBusinessIdOrFail(): int
    {
        $currentBusiness = app()->bound('currentBusiness') ? app('currentBusiness') : null;
        if (!$currentBusiness) {
            abort(403, 'Business context is required.');
        }

        return (int) $currentBusiness->id;
    }

    private function resolveCustomerOrFail(int $businessId, string $customer): Customer
    {
        if (!ctype_digit($customer)) {
            abort(404, 'Customer not found.');
        }

        $resolved = Customer::query()
            ->where('business_id', $businessId)
            ->whereKey((int) $customer)
            ->first();

        if (!$resolved) {
            abort(404, 'Customer not found.');
        }

        return $resolved;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min((int) $request->query('per_page', 20), 100));

        $query = Customer::query();

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $query->where(function ($sub) use ($search) {
                $sub->where('name', 'like', '%' . $search . '%')
                    ->orWhere('code', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%');
            });
        }

        $total = (clone $query)->count();
        $rows = $query
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => [
                'nullable',
                'string',
                'max:60',
                Rule::unique('customers', 'code')->where(
                    fn ($q) => $q->where('business_id', app('currentBusiness')->id)
                ),
            ],
            'name' => ['required', 'string', 'max:190'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:60'],
            'billing_address' => ['nullable', 'array'],
            'shipping_address' => ['nullable', 'array'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'currency' => ['nullable', 'string', 'max:10'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (!array_key_exists('is_active', $data) || $data['is_active'] === null) {
            $data['is_active'] = true;
        }

        $customer = Customer::create($data);

        return response()->json($customer, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $business, string $customer)
    {
        $businessId = $this->currentBusinessIdOrFail();
        $resolved = $this->resolveCustomerOrFail($businessId, $customer);

        return $resolved;
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $business, string $customer)
    {
        $businessId = $this->currentBusinessIdOrFail();
        $resolved = $this->resolveCustomerOrFail($businessId, $customer);

        $data = $request->validate([
            'code' => [
                'nullable',
                'string',
                'max:60',
                Rule::unique('customers', 'code')
                    ->where(fn ($q) => $q->where('business_id', app('currentBusiness')->id))
                    ->ignore($resolved->id),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:190'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:60'],
            'billing_address' => ['nullable', 'array'],
            'shipping_address' => ['nullable', 'array'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'currency' => ['nullable', 'string', 'max:10'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('is_active', $data) && $data['is_active'] === null) {
            unset($data['is_active']);
        }

        $resolved->update($data);

        return response()->json($resolved->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $business, string $customer)
    {
        $businessId = $this->currentBusinessIdOrFail();
        $resolved = $this->resolveCustomerOrFail($businessId, $customer);
        $resolved->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
