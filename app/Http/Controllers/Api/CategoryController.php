<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    private ?bool $hasBusinessColumn = null;
    private ?bool $hasActiveColumn = null;
    private ?bool $hasProductBusinessColumn = null;
    private ?bool $hasProductStockColumn = null;
    private ?bool $hasProductStockQuantityColumn = null;

    private function hasBusinessColumn(): bool
    {
        if ($this->hasBusinessColumn === null) {
            $this->hasBusinessColumn = Schema::hasColumn('categories', 'business_id');
        }

        return $this->hasBusinessColumn;
    }

    private function hasActiveColumn(): bool
    {
        if ($this->hasActiveColumn === null) {
            $this->hasActiveColumn = Schema::hasColumn('categories', 'is_active');
        }

        return $this->hasActiveColumn;
    }

    private function hasProductBusinessColumn(): bool
    {
        if ($this->hasProductBusinessColumn === null) {
            $this->hasProductBusinessColumn = Schema::hasColumn('products', 'business_id');
        }

        return $this->hasProductBusinessColumn;
    }

    private function hasProductStockColumn(): bool
    {
        if ($this->hasProductStockColumn === null) {
            $this->hasProductStockColumn = Schema::hasColumn('products', 'stock');
        }

        return $this->hasProductStockColumn;
    }

    private function hasProductStockQuantityColumn(): bool
    {
        if ($this->hasProductStockQuantityColumn === null) {
            $this->hasProductStockQuantityColumn = Schema::hasColumn('products', 'stock_quantity');
        }

        return $this->hasProductStockQuantityColumn;
    }

    private function getBusinessId(): ?int
    {
        if (app()->bound('currentBusiness')) {
            return app('currentBusiness')->id;
        }

        return auth()->user()?->businesses()->first()?->id;
    }

    private function resolveBusinessIdOrFail(): ?int
    {
        $businessId = $this->getBusinessId();

        if ($this->hasBusinessColumn() && !$businessId) {
            abort(403, 'Business introuvable pour cet utilisateur.');
        }

        return $businessId;
    }

    private function scopedQuery(?int $businessId): Builder
    {
        $query = Category::query();

        if ($this->hasBusinessColumn() && $businessId) {
            $query->where('business_id', $businessId);
        }

        return $query;
    }

    private function ensureCategoryOwnership(Category $category, ?int $businessId): void
    {
        if ($this->hasBusinessColumn() && $category->business_id != $businessId) {
            abort(403, 'Acces refuse pour cette categorie.');
        }
    }

    private function resolveCategoryFromRoute(string $category, ?int $businessId): Category
    {
        if (!ctype_digit($category)) {
            abort(422, 'Identifiant categorie invalide.');
        }

        $resolved = $this->scopedQuery($businessId)
            ->whereKey((int) $category)
            ->first();

        if (!$resolved) {
            abort(404, 'Categorie introuvable.');
        }

        return $resolved;
    }

    private function productScope(?int $businessId): Builder
    {
        $query = Product::query();

        if ($this->hasProductBusinessColumn() && $businessId) {
            $query->where('business_id', $businessId);
        }

        return $query;
    }

    private function hasStock(Category $category, ?int $businessId): bool
    {
        if (!$this->hasProductStockColumn() && !$this->hasProductStockQuantityColumn()) {
            return false;
        }

        $query = $this->productScope($businessId)->where('category_id', $category->id);

        return $query->where(function ($q) {
            if ($this->hasProductStockColumn()) {
                $q->orWhere('stock', '>', 0);
            }

            if ($this->hasProductStockQuantityColumn()) {
                $q->orWhere('stock_quantity', '>', 0);
            }
        })->exists();
    }

    private function hasSalesHistory(Category $category, ?int $businessId): bool
    {
        if (!Schema::hasTable('order_items') || !Schema::hasColumn('order_items', 'product_id')) {
            return false;
        }

        $query = DB::table('order_items')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('products.category_id', $category->id);

        if ($this->hasProductBusinessColumn() && $businessId) {
            $query->where('products.business_id', $businessId);
        }

        return $query->exists();
    }

    public function index(Request $request)
    {
        $businessId = $this->resolveBusinessIdOrFail();
        $query = $this->scopedQuery($businessId)->orderBy('name');

        if ($this->hasActiveColumn() && !$request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        if ($request->boolean('all')) {
            return response()->json($query->get());
        }

        $perPage = max(1, min((int) $request->query('per_page', 20), 100));

        return response()->json($query->paginate($perPage));
    }

    public function show(string $business, string $category)
    {
        $businessId = $this->resolveBusinessIdOrFail();
        $resolvedCategory = $this->resolveCategoryFromRoute($category, $businessId);

        return response()->json($resolvedCategory);
    }

    public function store(Request $request)
    {
        $businessId = $this->resolveBusinessIdOrFail();
        $slugRule = Rule::unique('categories', 'slug');

        if ($this->hasBusinessColumn() && $businessId) {
            $slugRule = $slugRule->where('business_id', $businessId);
        }

        $rules = [
            'name' => 'required|string|max:255',
            'slug' => ['nullable', 'string', 'max:255', $slugRule],
            'description' => 'nullable|string',
        ];

        if ($this->hasActiveColumn()) {
            $rules['is_active'] = 'sometimes|boolean';
        }

        $validated = $request->validate($rules);

        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['name']);

        if (empty($validated['slug'])) {
            $validated['slug'] = 'categorie-' . uniqid();
        }

        if ($this->hasBusinessColumn() && $businessId) {
            $validated['business_id'] = $businessId;
        }

        if ($this->hasActiveColumn() && !array_key_exists('is_active', $validated)) {
            $validated['is_active'] = true;
        }

        $category = Category::create($validated);

        return response()->json([
            'message' => 'Categorie creee avec succes.',
            'category' => $category,
        ], 201);
    }

    public function update(Request $request, string $business, string $category)
    {
        $businessId = $this->resolveBusinessIdOrFail();
        $resolvedCategory = $this->resolveCategoryFromRoute($category, $businessId);

        $slugRule = Rule::unique('categories', 'slug')->ignore($resolvedCategory->id);

        if ($this->hasBusinessColumn() && $businessId) {
            $slugRule = $slugRule->where('business_id', $businessId);
        }

        $rules = [
            'name' => 'sometimes|required|string|max:255',
            'slug' => ['nullable', 'string', 'max:255', $slugRule],
            'description' => 'sometimes|nullable|string',
        ];

        if ($this->hasActiveColumn()) {
            $rules['is_active'] = 'sometimes|boolean';
        }

        $validated = $request->validate($rules);

        if (empty($validated)) {
            return response()->json([
                'message' => 'Aucune donnee a mettre a jour.',
            ], 422);
        }

        if (!array_key_exists('slug', $validated) && array_key_exists('name', $validated)) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        if (array_key_exists('slug', $validated) && empty($validated['slug'])) {
            $validated['slug'] = 'categorie-' . $resolvedCategory->id;
        }

        if ($this->hasBusinessColumn() && $businessId) {
            $validated['business_id'] = $businessId;
        }

        $resolvedCategory->update($validated);

        return response()->json([
            'message' => 'Categorie mise a jour.',
            'category' => $resolvedCategory->fresh(),
        ]);
    }

    public function destroy(string $business, string $category)
    {
        $businessId = $this->resolveBusinessIdOrFail();
        $resolvedCategory = $this->resolveCategoryFromRoute($category, $businessId);

        $hasStock = $this->hasStock($resolvedCategory, $businessId);
        $hasSalesHistory = $this->hasSalesHistory($resolvedCategory, $businessId);

        if ($hasStock || $hasSalesHistory) {
            return response()->json([
                'message' => 'Suppression impossible: renommez ou desactivez cette categorie.',
                'has_stock' => $hasStock,
                'has_sales_history' => $hasSalesHistory,
            ], 422);
        }

        $this->productScope($businessId)->where('category_id', $resolvedCategory->id)->update(['category_id' => null]);
        $resolvedCategory->delete();

        return response()->json(['message' => 'Categorie supprimee.']);
    }
}
