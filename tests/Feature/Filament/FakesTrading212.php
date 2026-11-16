<?php

namespace Tests\Feature\Filament;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

/**
 * Canned Trading 212 API responses for UI tests (feature + browser).
 */
final class FakesTrading212
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(): array
    {
        return [
            'id' => 7654321,
            'currency' => 'GBP',
            'totalValue' => 10500.5,
            'cash' => ['availableToTrade' => 2500.25, 'reservedForOrders' => 100, 'inPies' => 0],
            'investments' => [
                'currentValue' => 8000.5,
                'totalCost' => 7500,
                'realizedProfitLoss' => 120.25,
                'unrealizedProfitLoss' => 500.5,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function positions(): array
    {
        return [
            [
                'instrument' => [
                    'ticker' => 'AAPL_US_EQ',
                    'name' => 'Apple Inc',
                    'isin' => 'US0378331005',
                    'currency' => 'USD',
                ],
                'quantity' => 10,
                'quantityAvailableForTrading' => 10,
                'averagePricePaid' => 150,
                'currentPrice' => 180,
                'walletImpact' => [
                    'currentValue' => 1440,
                    'totalCost' => 1200,
                    'unrealizedProfitLoss' => 240,
                    'fxImpact' => null,
                    'currency' => 'GBP',
                ],
            ],
            [
                'instrument' => [
                    'ticker' => 'TSLA_US_EQ',
                    'name' => 'Tesla Inc',
                    'isin' => 'US88160R1014',
                    'currency' => 'USD',
                ],
                'quantity' => 2,
                'quantityAvailableForTrading' => 2,
                'averagePricePaid' => 300,
                'currentPrice' => 250,
                'walletImpact' => [
                    'currentValue' => 400,
                    'totalCost' => 480,
                    'unrealizedProfitLoss' => -80,
                    'fxImpact' => null,
                    'currency' => 'GBP',
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function instruments(): array
    {
        return [
            [
                'ticker' => 'AAPL_US_EQ',
                'shortName' => 'AAPL',
                'name' => 'Apple Inc',
                'isin' => 'US0378331005',
                'currencyCode' => 'USD',
                'type' => 'STOCK',
            ],
            [
                'ticker' => 'MSFT_US_EQ',
                'shortName' => 'MSFT',
                'name' => 'Microsoft Corporation',
                'isin' => 'US5949181045',
                'currencyCode' => 'USD',
                'type' => 'STOCK',
            ],
            [
                'ticker' => 'TSLA_US_EQ',
                'shortName' => 'TSLA',
                'name' => 'Tesla Inc',
                'isin' => 'US88160R1014',
                'currencyCode' => 'USD',
                'type' => 'STOCK',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function pendingOrders(): array
    {
        return [
            [
                'id' => 555,
                'ticker' => 'MSFT_US_EQ',
                'type' => 'LIMIT',
                'side' => 'BUY',
                'status' => 'NEW',
                'quantity' => 3,
                'filledQuantity' => 0,
                'limitPrice' => 300.5,
                'currency' => 'USD',
                'createdAt' => '2026-11-14T10:00:00Z',
            ],
        ];
    }

    /**
     * Fake every Trading 212 endpoint for the given environment ('demo' or 'live'). Extra
     * `$overrides` (url pattern => response) take precedence.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function fake(string $environment = 'demo', array $overrides = []): Factory
    {
        $base = "{$environment}.trading212.com/api/v0/equity";

        return Http::fake($overrides + [
            "{$base}/account/summary*" => Http::response(self::summary()),
            "{$base}/positions*" => Http::response(self::positions()),
            "{$base}/metadata/instruments*" => Http::response(self::instruments()),
            "{$base}/orders/market*" => Http::response([
                'id' => 9001,
                'status' => 'NEW',
                'ticker' => 'AAPL_US_EQ',
                'type' => 'MARKET',
            ]),
            "{$base}/orders/limit*" => Http::response([
                'id' => 9002,
                'status' => 'NEW',
                'ticker' => 'AAPL_US_EQ',
                'type' => 'LIMIT',
            ]),
            "{$base}/orders/stop*" => Http::response(['id' => 9003, 'status' => 'NEW', 'type' => 'STOP']),
            "{$base}/orders/stop_limit*" => Http::response(['id' => 9004, 'status' => 'NEW', 'type' => 'STOP_LIMIT']),
            "{$base}/orders/*" => Http::response([]),
            "{$base}/orders*" => Http::response(self::pendingOrders()),
            "{$base}/history/orders*" => Http::response([
                'items' => [[
                    'order' => [
                        'id' => 1,
                        'ticker' => 'AAPL_US_EQ',
                        'type' => 'MARKET',
                        'side' => 'BUY',
                        'status' => 'FILLED',
                        'quantity' => 1,
                        'filledQuantity' => 1,
                        'createdAt' => '2026-11-01T09:00:00Z',
                        'instrument' => ['ticker' => 'AAPL_US_EQ', 'name' => 'Apple Inc', 'currency' => 'USD'],
                    ],
                    'fill' => [
                        'price' => 170,
                        'quantity' => 1,
                        'filledAt' => '2026-11-01T09:00:01Z',
                        'walletImpact' => ['netValue' => 135.5, 'currency' => 'GBP', 'realisedProfitLoss' => 0],
                    ],
                ]],
                'nextPagePath' => '/api/v0/equity/history/orders?limit=50&cursor=1760346100000',
            ]),
            "{$base}/history/dividends*" => Http::response([
                'items' => [[
                    'ticker' => 'AAPL_US_EQ',
                    'amount' => 1.23,
                    'currency' => 'GBP',
                    'paidOn' => '2026-10-01T00:00:00Z',
                    'quantity' => 10,
                    'type' => 'ORDINARY',
                    'reference' => 'DIV-1',
                ]],
                'nextPagePath' => null,
            ]),
            "{$base}/history/transactions*" => Http::response([
                'items' => [[
                    'amount' => 1000,
                    'currency' => 'GBP',
                    'dateTime' => '2026-09-01T00:00:00Z',
                    'type' => 'DEPOSIT',
                    'reference' => 'TX-REF-1',
                ]],
                'nextPagePath' => null,
            ]),
        ]);
    }
}
