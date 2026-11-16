<?php

use App\Mcp\CurrentAgent;
use App\Mcp\Servers\LocalTradingServer;
use App\Mcp\Servers\TradingServer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Facades\Mcp;

RateLimiter::for('mcp-agent', fn (Request $request): Limit => Limit::perMinute(120)
    ->by('mcp-agent:'.(app(CurrentAgent::class)->get()?->getKey() ?? $request->ip())));

Mcp::web('/mcp/trading', TradingServer::class)
    ->name('mcp.trading')
    ->middleware(['auth.agent', 'throttle:mcp-agent']);

Mcp::local('trading', LocalTradingServer::class);
