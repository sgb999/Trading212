<?php

/*
|--------------------------------------------------------------------------
| Trading 212 Public API
|--------------------------------------------------------------------------
|
| Rate limits are taken from the official OpenAPI spec (docs/trading212-openapi.yaml)
| and are enforced per Trading 212 *account* (not per key), which mirrors how
| Trading 212 applies them. `cache_ttl` is how long GET responses are cached in
| the Postgres-backed cache so dashboards / AI agents never hammer the API.
|
*/

return [

    'base_urls' => [
        'demo' => env('TRADING212_DEMO_URL', 'https://demo.trading212.com/api/v0'),
        'live' => env('TRADING212_LIVE_URL', 'https://live.trading212.com/api/v0'),
    ],

    'timeout' => (int) env('TRADING212_TIMEOUT', 15),

    /*
     | Maximum number of seconds a request will wait for a rate limit slot
     | before giving up with a RateLimitExceededException.
     */
    'max_wait_seconds' => (int) env('TRADING212_MAX_WAIT', 10),

    /*
     | Per-account lock that serialises order authorisation and placement. Its
     | lifetime is derived from these values, `timeout` and `max_wait_seconds`
     | (see App\Actions\PlaceOrder::lockTtlSeconds()): `max_api_calls` is the
     | most Trading 212 calls one placement may make while holding it, and
     | `margin_seconds` is added on top for database work.
     */
    'order_lock' => [
        'max_api_calls' => (int) env('TRADING212_ORDER_LOCK_MAX_API_CALLS', 6),
        'margin_seconds' => (int) env('TRADING212_ORDER_LOCK_MARGIN_SECONDS', 60),
    ],

    /*
     | Cache store used for the rate limiter, response cache and order lock.
     */
    'cache_store' => env('TRADING212_CACHE_STORE'),

    /*
     | endpoint key => [max requests, period seconds, GET cache ttl seconds]
     */
    'endpoints' => [
        'account.summary' => ['limit' => 1, 'period' => 5, 'cache_ttl' => 5],
        'positions' => ['limit' => 1, 'period' => 1, 'cache_ttl' => 2],
        'orders.list' => ['limit' => 1, 'period' => 5, 'cache_ttl' => 5],
        'orders.show' => ['limit' => 1, 'period' => 1, 'cache_ttl' => 2],
        'orders.market' => ['limit' => 50, 'period' => 60, 'cache_ttl' => 0],
        'orders.limit' => ['limit' => 1, 'period' => 2, 'cache_ttl' => 0],
        'orders.stop' => ['limit' => 1, 'period' => 2, 'cache_ttl' => 0],
        'orders.stop_limit' => ['limit' => 1, 'period' => 2, 'cache_ttl' => 0],
        'orders.cancel' => ['limit' => 50, 'period' => 60, 'cache_ttl' => 0],
        'metadata.instruments' => ['limit' => 1, 'period' => 50, 'cache_ttl' => 21600],
        'metadata.exchanges' => ['limit' => 1, 'period' => 30, 'cache_ttl' => 21600],
        'history.orders' => ['limit' => 20, 'period' => 60, 'cache_ttl' => 60],
        'history.dividends' => ['limit' => 20, 'period' => 60, 'cache_ttl' => 300],
        'history.transactions' => ['limit' => 20, 'period' => 60, 'cache_ttl' => 300],
    ],

];
