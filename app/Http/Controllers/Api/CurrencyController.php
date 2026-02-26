<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CurrencyController extends Controller
{
    public function index()
    {
        $cacheKey = 'currencies:openexchangerates:v1';

        $cached = Cache::get($cacheKey);
        if (is_array($cached) && count($cached) > 0) {
            return response()->json([
                'data' => $cached,
                'source' => 'cache',
            ]);
        }

        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->get('https://openexchangerates.org/api/currencies.json');

            if ($response->successful()) {
                $payload = $response->json();

                if (is_array($payload) && count($payload) > 0) {
                    ksort($payload);

                    $items = [];
                    foreach ($payload as $code => $name) {
                        if (!is_string($code) || !is_string($name)) {
                            continue;
                        }

                        $trimmedCode = strtoupper(trim($code));
                        $trimmedName = trim($name);
                        if ($trimmedCode === '' || $trimmedName === '') {
                            continue;
                        }

                        $items[] = [
                            'code' => $trimmedCode,
                            'name' => $trimmedName,
                        ];
                    }

                    if (count($items) > 0) {
                        Cache::put($cacheKey, $items, now()->addHours(24));

                        return response()->json([
                            'data' => $items,
                            'source' => 'online',
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Silent fallback below.
        }

        $fallback = [
            ['code' => 'USD', 'name' => 'United States Dollar'],
            ['code' => 'EUR', 'name' => 'Euro'],
            ['code' => 'GBP', 'name' => 'British Pound Sterling'],
            ['code' => 'CAD', 'name' => 'Canadian Dollar'],
            ['code' => 'JPY', 'name' => 'Japanese Yen'],
        ];

        return response()->json([
            'data' => $fallback,
            'source' => 'fallback',
        ]);
    }
}

