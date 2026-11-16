<?php

use App\Models\AiAgent;
use App\Models\Role;
use App\Models\TradeOrder;
use App\Models\TradingAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Feature\Filament\FakesTrading212;

beforeEach(function () {
    Sleep::fake();
    FakesTrading212::fake();
    $this->user = User::factory()->create(['email' => 'owner@example.com']);
    $this->account = TradingAccount::factory()->for($this->user)->default()->create(['name' => 'Paper account']);
});

it('signs in and lands on the dashboard', function () {
    visit('/')
        ->assertPathIs('/login')
        ->fill('input[type=email]', 'owner@example.com')
        ->fill('input[type=password]', 'password')
        ->press('button[type=submit]')
        ->waitForText('Total value')
        ->assertPathIs('/')
        ->assertSee('Trading Platform')
        ->assertNoJavaScriptErrors();
});

it('renders the dashboard with account data and positions', function () {
    $this->actingAs($this->user);

    visit('/')
        ->waitForText('Open positions')
        ->assertSee('Paper account')
        ->assertSee('£10,500.50')
        ->assertSee('£2,500.25')
        ->assertSee('AAPL_US_EQ')
        ->assertSee('Apple Inc')
        ->assertSee('+20.00%')
        ->assertSee('DEMO')
        ->assertNoJavaScriptErrors();
});

it('places a demo order end to end from the portfolio', function () {
    $this->actingAs($this->user);

    visit('/portfolio')
        ->waitForText('TSLA_US_EQ')
        ->assertSee('Buy more')
        ->navigate('/trade?ticker=AAPL_US_EQ&side=BUY')
        ->waitForText('New order')
        ->assertSee('Apple Inc')
        ->fill('input[id$="quantity"]', '2')
        ->press('button:has-text("Place order")')
        ->waitForText('Order placed')
        ->assertSee('Buy 2 × AAPL_US_EQ (Market)')
        ->assertNoJavaScriptErrors();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://demo.trading212.com/api/v0/equity/orders/market'
        && $request['quantity'] == 2);

    expect(TradeOrder::sole()->placedBy->is($this->user))->toBeTrue();
});

it('asks for confirmation before a live order', function () {
    FakesTrading212::fake('live');
    TradingAccount::factory()->for($this->user)->live()->create(['name' => 'Real ISA', 'is_default' => true]);
    $this->actingAs($this->user);

    visit('/trade?ticker=AAPL_US_EQ&side=BUY')
        ->waitForText('Live account — real money')
        ->assertSee('Orders use real money.')
        ->fill('input[id$="quantity"]', '1')
        ->press('button:has-text("Place LIVE order")')
        ->waitForText('Confirm LIVE order')
        ->assertSee('REAL-MONEY order')
        ->press('.fi-modal button:has-text("Yes, place live order")')
        ->waitForText('Order placed')
        ->assertNoJavaScriptErrors();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_starts_with($request->url(), 'https://live.trading212.com/'));
});

it('creates an agent and shows its token once', function () {
    $role = Role::factory()->trader()->create(['name' => 'Demo trader']);
    $this->actingAs($this->user);

    $page = visit('/ai-agents/create')
        ->waitForText('Create AI agent')
        ->fill('input[id$=".name"]', 'Momentum bot')
        ->select('select[id$="role_id"]', (string) $role->id)
        ->press('form#form button[type=submit]')
        ->waitForText('Copy the agent token now')
        ->assertSee('/mcp/trading')
        ->assertSee('Authorization: Bearer t212a_');

    $agent = AiAgent::sole();

    expect($agent->name)->toBe('Momentum bot');

    $page->assertSee($agent->token_prefix)
        ->press('.fi-modal button:has-text("I have copied the token")')
        ->navigate("/ai-agents/{$agent->id}")
        ->waitForText('MCP connection')
        ->assertDontSee('Copy the agent token now')
        ->assertNoJavaScriptErrors();
});
