<?php

use App\Data\OrderRequest;
use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Enums\TimeValidity;

test('it builds an order from loose input', function () {
    $order = OrderRequest::fromArray([
        'ticker' => ' AAPL_US_EQ ',
        'type' => 'limit',
        'side' => 'sell',
        'quantity' => '2.5',
        'limit_price' => '101.5',
        'stop_price' => '',
        'time_validity' => 'good_till_cancel',
    ]);

    expect($order)
        ->ticker->toBe('AAPL_US_EQ')
        ->type->toBe(OrderType::Limit)
        ->side->toBe(OrderSide::Sell)
        ->limitPrice->toBe(101.5)
        ->stopPrice->toBeNull()
        ->timeValidity->toBe(TimeValidity::GoodTillCancel)
        ->and($order->signedQuantity())->toBe(-2.5)
        ->and($order->knownPrice())->toBe(101.5);
});

test('buy quantities are positive', function () {
    expect(new OrderRequest('AAPL_US_EQ', OrderType::Market, OrderSide::Buy, 3)->signedQuantity())->toBe(3.0);
});

test('it rejects invalid orders', function (array $data) {
    expect(fn () => OrderRequest::fromArray($data))->toThrow(InvalidArgumentException::class);
})->with([
    'zero quantity' => [['ticker' => 'X', 'type' => 'MARKET', 'side' => 'BUY', 'quantity' => 0]],
    'limit without price' => [['ticker' => 'X', 'type' => 'LIMIT', 'side' => 'BUY', 'quantity' => 1]],
    'stop without price' => [['ticker' => 'X', 'type' => 'STOP', 'side' => 'BUY', 'quantity' => 1]],
    'stop limit without stop price' => [
        ['ticker' => 'X', 'type' => 'STOP_LIMIT', 'side' => 'BUY', 'quantity' => 1, 'limit_price' => 5],
    ],
    'unknown order type' => [['ticker' => 'X', 'type' => 'TRAILING', 'side' => 'BUY', 'quantity' => 1]],
    'unknown side' => [['ticker' => 'X', 'type' => 'MARKET', 'side' => 'HOLD', 'quantity' => 1]],
    'unknown time validity' => [
        ['ticker' => 'X', 'type' => 'MARKET', 'side' => 'BUY', 'quantity' => 1, 'time_validity' => 'FOREVER'],
    ],
]);

test('it accepts enum instances and defaults time validity to day', function () {
    $order = OrderRequest::fromArray([
        'ticker' => 'AAPL_US_EQ',
        'type' => OrderType::Market,
        'side' => OrderSide::Buy,
        'quantity' => 1,
        'time_validity' => null,
    ]);

    expect($order)
        ->type->toBe(OrderType::Market)
        ->side->toBe(OrderSide::Buy)
        ->timeValidity->toBe(TimeValidity::Day);
});
