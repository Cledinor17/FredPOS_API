<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class ProductController extends Controller
{
    private array $columnCache = [];
    private ?bool $hasCategoryBusinessColumn = null;

    private function getBusinessId(): ?int
    {
        if (app()->bound('currentBusiness')) {
            return app('currentBusiness')->id;
        }

        return auth()->user()?->businesses()->first()?->id;
    }

    private function hasProductColumn(string $column): bool
    {
        if (!array_key_exists($column, $this->columnCache)) {
            $this->columnCache[$column] = Schema::hasColumn('products', $column);
        }

        return $this->columnCache[$column];
    }

    private function hasCategoryBusinessColumn(): bool
    {
        if ($this->hasCategoryBusinessColumn === null) {
            $this->hasCategoryBusinessColumn = Schema::hasColumn('categories', 'business_id');
        }

        return $this->hasCategoryBusinessColumn;
    }

    private function resolveBusinessIdOrFail(): ?int
    {
        $businessId = $this->getBusinessId();

        if ($this->hasProductColumn('business_id') && !$businessId) {
            abort(403, 'Business introuvable pour cet utilisateur.');
        }

        return $businessId;
    }

    private function applyBusinessScope(Builder $query, ?int $businessId): Builder
    {
        if ($this->hasProductColumn('business_id') && $businessId) {
            $query->where('business_id', $businessId);
        }

        return $query;
    }

    private function uniqueRule(string $column, ?int $businessId, ?Product $product = null): Unique
    {
        $rule = Rule::unique('products', $column);

        if ($this->hasProductColumn('business_id') && $businessId) {
            $rule = $rule->where('business_id', $businessId);
        }

        if ($product) {
            $rule = $rule->ignore($product->id);
        }

        return $rule;
    }

    private function validationRules(?int $businessId, bool $isUpdate = false, ?Product $product = null): array
    {
        $costPriceRule = $isUpdate ? 'nullable|numeric|min:0' : 'required|numeric|min:0';
        $stockQuantityRule = $isUpdate ? 'nullable|integer|min:0' : 'required|integer|min:0';
        $categoryExistsRule = Rule::exists('categories', 'id');

        if ($this->hasCategoryBusinessColumn() && $businessId) {
            $categoryExistsRule = $categoryExistsRule->where('business_id', $businessId);
        }

        $rules = [
            'name' => 'required|string|max:255',
            'category_id' => ['nullable', $categoryExistsRule],
            // Compat: accepte les anciennes valeurs puis normalise vers product/service avant save.
            'type' => 'required|in:product,service,standard,dish,location',
            'cost_price' => $costPriceRule,
            'selling_price' => 'required|numeric|min:0',
            'stock_quantity' => $stockQuantityRule,
            'alert_quantity' => 'nullable|integer|min:0',
            'image' => 'nullable|image|max:2048',
            'is_active' => 'boolean',
        ];

        if ($this->hasProductColumn('department')) {
            $rules['department'] = 'required|string|max:100';
        }

        if ($this->hasProductColumn('barcode')) {
            $rules['barcode'] = ['nullable', 'string', 'max:255', $this->uniqueRule('barcode', $businessId, $product)];
        }

        if ($this->hasProductColumn('sku')) {
            $rules['sku'] = ['nullable', 'string', 'max:255', $this->uniqueRule('sku', $businessId, $product)];
        }

        if ($this->hasProductColumn('track_inventory')) {
            $rules['track_inventory'] = 'boolean';
        }

        return $rules;
    }

    private function normalizeType(string $type): string
    {
        return match ($type) {
            'standard', 'dish' => 'product',
            'location' => 'service',
            default => $type,
        };
    }

    private function assertProductOwnership(Product $product, ?int $businessId): void
    {
        if ($this->hasProductColumn('business_id') && $product->business_id != $businessId) {
            abort(403, 'Action non autorisee pour ce business.');
        }
    }

    public function index(Request $request)
    {
        $businessId = $this->resolveBusinessIdOrFail();

        $query = $this->applyBusinessScope(Product::query(), $businessId)
            ->with('category')
            ->orderByDesc('created_at');

        if ($request->boolean('all')) {
            return response()->json($query->get());
        }

        $perPage = max(1, min((int) $request->query('per_page', 10), 100));

        return response()->json($query->paginate($perPage));
    }

    public function show(string $business, Product $product)
    {
        $businessId = $this->resolveBusinessIdOrFail();
        $this->assertProductOwnership($product, $businessId);

        return response()->json($product->load('category'));
    }

    public function store(Request $request)
    {
        $businessId = $this->resolveBusinessIdOrFail();
        $validated = $request->validate($this->validationRules($businessId));
        $validated['type'] = $this->normalizeType($validated['type']);

        if ($request->hasFile('image')) {
            $validated['image_path'] = $request->file('image')->store('products', 'public');
        }

        if ($this->hasProductColumn('business_id') && $businessId) {
            $validated['business_id'] = $businessId;
        }

        if ($this->hasProductColumn('stock') && array_key_exists('stock_quantity', $validated)) {
            $validated['stock'] = $validated['stock_quantity'];
        }

        $product = Product::create($validated)->load('category');

        return response()->json([
            'message' => 'Produit cree avec succes.',
            'product' => $product,
        ], 201);
    }

    public function update(Request $request, string $business, Product $product)
    {
        $businessId = $this->resolveBusinessIdOrFail();
        $this->assertProductOwnership($product, $businessId);

        $validated = $request->validate($this->validationRules($businessId, true, $product));

        if (array_key_exists('type', $validated)) {
            $validated['type'] = $this->normalizeType($validated['type']);
        }

        if ($request->hasFile('image')) {
            if ($product->image_path) {
                Storage::disk('public')->delete($product->image_path);
            }

            $validated['image_path'] = $request->file('image')->store('products', 'public');
        }

        if ($this->hasProductColumn('stock') && array_key_exists('stock_quantity', $validated)) {
            $validated['stock'] = $validated['stock_quantity'];
        }

        $product->update($validated);

        return response()->json([
            'message' => 'Produit mis a jour.',
            'product' => $product->fresh()->load('category'),
        ]);
    }

    public function destroy(string $business, Product $product)
    {
        $businessId = $this->resolveBusinessIdOrFail();
        $this->assertProductOwnership($product, $businessId);

        if ($product->image_path) {
            Storage::disk('public')->delete($product->image_path);
        }

        $product->delete();

        return response()->json(['message' => 'Produit supprime.']);
    }
}
