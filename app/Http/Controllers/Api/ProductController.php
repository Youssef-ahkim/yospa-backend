<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    private function escapeLike(string $value, string $char = '='): string
    {
        return str_replace(
            [$char, '%', '_'],
            [$char.$char, $char.'%', $char.'_'],
            $value
        );
    }

    public function index(Request $request)
    {
        $request->validate([
            'page'      => 'sometimes|integer|min:1',
            'per_page'  => 'sometimes|integer|min:1',
            'all'       => 'sometimes|boolean',
            'low_stock' => 'sometimes|boolean',
            'search'    => 'sometimes|nullable|string|max:255',
            'sort'      => 'sometimes|nullable|string|in:name,price,stock_quantity,created_at',
            'direction' => 'sometimes|nullable|string|in:asc,desc,ASC,DESC',
        ]);

        $query = Product::query();

        if (! $request->boolean('all')) {
            $query->where('is_active', true);
        }

        if ($request->boolean('low_stock')) {
            $query->whereColumn('stock_quantity', '<=', 'low_stock_threshold');
        }

        if ($request->filled('search')) {
            $term = $this->escapeLike(trim($request->search), '=');
            $pattern = "%{$term}%";
            $query->where(function ($q) use ($pattern) {
                $q->whereRaw("name LIKE ? ESCAPE '='", [$pattern])
                  ->orWhereRaw("sku LIKE ? ESCAPE '='", [$pattern]);
            });
        }

        $sort = $request->input('sort', 'name');
        $direction = strtolower($request->input('direction', 'asc'));
        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }

        $query->orderBy($sort, $direction)->orderBy('id', $direction);

        if ($request->has('page') || $request->has('per_page')) {
            $perPage = min((int) $request->input('per_page', 25), 100);
            return response()->json($query->paginate($perPage)->withQueryString());
        }

        return response()->json($query->get())->header('X-Legacy-Unpaginated', '1');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    $spaId = auth()->user()->spa_id;
                    $exists = Product::where('spa_id', $spaId)
                        ->whereRaw('LOWER(name) = ?', [strtolower(trim($value))])
                        ->exists();

                    if ($exists) {
                        $fail('A product with this name already exists.');
                    }
                },
            ],
            'sku' => [
                'nullable',
                'string',
                'max:100',
                function ($attribute, $value, $fail) {
                    if (empty($value) || trim($value) === '') {
                        return;
                    }
                    $spaId = auth()->user()->spa_id;
                    $exists = Product::where('spa_id', $spaId)
                        ->whereRaw('LOWER(sku) = ?', [strtolower(trim($value))])
                        ->exists();

                    if ($exists) {
                        $fail('A product with this SKU already exists.');
                    }
                },
            ],
            'unit'                => 'sometimes|string|max:30',
            'price'               => 'required|numeric|min:0',
            'stock_quantity'      => 'sometimes|integer|min:0',
            'low_stock_threshold' => 'sometimes|integer|min:0',
            'description'         => 'nullable|string|max:2000',
            'is_active'           => 'sometimes|boolean',
        ]);

        $product = Product::create($validated);

        return response()->json([
            'message' => 'Product created successfully',
            'product' => $product,
        ], 201);
    }

    public function show(Product $product)
    {
        return response()->json($product);
    }

    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($product) {
                    $spaId = auth()->user()->spa_id;
                    $exists = Product::where('spa_id', $spaId)
                        ->where('id', '!=', $product->id)
                        ->whereRaw('LOWER(name) = ?', [strtolower(trim($value))])
                        ->exists();

                    if ($exists) {
                        $fail('A product with this name already exists.');
                    }
                },
            ],
            'sku' => [
                'nullable',
                'string',
                'max:100',
                function ($attribute, $value, $fail) use ($product) {
                    if (empty($value) || trim($value) === '') {
                        return;
                    }
                    $spaId = auth()->user()->spa_id;
                    $exists = Product::where('spa_id', $spaId)
                        ->where('id', '!=', $product->id)
                        ->whereRaw('LOWER(sku) = ?', [strtolower(trim($value))])
                        ->exists();

                    if ($exists) {
                        $fail('A product with this SKU already exists.');
                    }
                },
            ],
            'unit'                => 'sometimes|string|max:30',
            'price'               => 'sometimes|numeric|min:0',
            'stock_quantity'      => 'sometimes|integer|min:0',
            'low_stock_threshold' => 'sometimes|integer|min:0',
            'description'         => 'nullable|string|max:2000',
            'is_active'           => 'sometimes|boolean',
        ]);

        $product->update($validated);

        return response()->json([
            'message' => 'Product updated successfully',
            'product' => $product,
        ]);
    }

    public function destroy(Product $product)
    {
        $isUsed = DB::table('reservation_products')
            ->where('product_id', $product->id)
            ->exists();

        if ($isUsed) {
            return response()->json([
                'message' => "This product is used in existing reservations and can't be deleted. Deactivate it instead.",
            ], 409);
        }

        $product->delete();

        return response()->json([
            'message' => 'Product deleted successfully',
        ]);
    }
}
