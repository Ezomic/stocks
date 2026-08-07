@extends('layout')
@section('title', 'Dashboard')
@section('content')

@if($dryRun)
<div class="auth-banner">
    &#9679; <strong>Dry run is on.</strong> Triggered rules are recorded as simulated orders and nothing reaches IBKR.
    <a href="/settings" class="btn btn-sm">Settings</a>
</div>
@endif

@unless($tradingEnabled)
<div class="auth-banner">
    &#9679; <strong>Automated trading is paused.</strong> No orders will be placed.
    <a href="/settings" class="btn btn-sm">Resume in settings</a>
</div>
@endunless

<div class="auth-banner {{ $ibkrAuthenticated ? 'ok' : '' }}">
    @if($ibkrAuthenticated)
        &#9679; IBKR gateway connected ({{ config('ibkr.mode') }} mode)
    @else
        &#9679; IBKR gateway not authenticated &mdash;
        <form method="POST" action="/ibkr/reauth" style="margin:0">
            @csrf
            <button type="submit" class="btn btn-sm">Re-authenticate</button>
        </form>
    @endif
</div>

@if($driftedPositions->isNotEmpty())
<div class="auth-banner">
    &#9679; <strong>{{ $driftedPositions->count() }} {{ Str::plural('position', $driftedPositions->count()) }} {{ $driftedPositions->count() === 1 ? 'does' : 'do' }} not match the broker.</strong>
    @foreach($driftedPositions as $p)
        {{ $p->symbol }} (here {{ rtrim(rtrim($p->quantity, '0'), '.') }}, IBKR {{ rtrim(rtrim($p->broker_quantity, '0'), '.') }}){{ ! $loop->last ? ',' : '' }}
    @endforeach
    Rules are measured against the local number.
</div>
@endif

@if($unreconciledCount > 0)
<div class="auth-banner">
    &#9679; <strong>{{ $unreconciledCount }} {{ Str::plural('order', $unreconciledCount) }} could not be reconciled.</strong>
    The broker stopped reporting {{ $unreconciledCount === 1 ? 'it' : 'them' }} before the outcome was known.
    <a href="/orders" class="btn btn-sm">Review</a>
</div>
@endif

@if($stalePriceCount > 0)
<div class="auth-banner">
    &#9679; {{ $stalePriceCount }} {{ Str::plural('position', $stalePriceCount) }}
    {{ $stalePriceCount === 1 ? 'has' : 'have' }} no price newer than {{ $maxPriceAgeMinutes }} minutes.
    Rules are not being evaluated for {{ $stalePriceCount === 1 ? 'it' : 'them' }} until price sync recovers.
</div>
@endif

@if($inactiveAccountCount > 0)
<div class="auth-banner">
    &#9679; {{ $inactiveAccountCount }} {{ Str::plural('position', $inactiveAccountCount) }}
    {{ $inactiveAccountCount === 1 ? 'belongs' : 'belong' }} to another broker account and
    {{ $inactiveAccountCount === 1 ? 'is' : 'are' }} not being priced or traded.
    Active account: {{ $activeAccountId ?: 'not configured' }} ({{ $activeMode }} mode).
</div>
@endif

<div class="stats-row">
    @forelse($totals as $total)
    <div class="stat">
        <div class="stat-label">
            Value ({{ $total['currency'] }})
            @if(count($totals) > 1)<span style="color:var(--muted)"> · {{ $total['positions'] }} {{ Str::plural('position', $total['positions']) }}</span>@endif
        </div>
        <div class="stat-value">{{ $total['value'] > 0 ? $total['currency'].' '.number_format($total['value'], 2) : '—' }}</div>
        @if($total['gainPct'] !== null)
        <div class="{{ $total['gainPct'] >= 0 ? 'gain' : 'loss' }}" style="font-size:13px">
            {{ sprintf('%+.2f', $total['gainPct']) }}%
        </div>
        @endif
    </div>
    @empty
    <div class="stat">
        <div class="stat-label">Portfolio value</div>
        <div class="stat-value">—</div>
    </div>
    @endforelse
    <div class="stat">
        <div class="stat-label">Positions</div>
        <div class="stat-value">{{ $positions->count() }}</div>
    </div>
</div>

<div class="page-header">
    <h1>Positions</h1>
    <a href="/positions/create" class="btn btn-primary">+ Add position</a>
</div>

@if($positions->isEmpty())
    <div class="card" style="color:var(--muted);text-align:center;padding:40px;">No positions yet. <a href="/positions/create">Add one</a> or <a href="/settings">import from IBKR</a>.</div>
@else
<div class="card" style="padding:0">
    <table>
        <thead><tr>
            <th>Symbol</th><th>Market</th><th>Qty</th><th>Avg buy</th>
            <th>Current</th><th>Gain/Loss</th><th>Rules</th><th></th>
        </tr></thead>
        <tbody>
        @foreach($positions as $p)
        <tr>
            <td><a href="/positions/{{ $p->id }}"><strong>{{ $p->symbol }}</strong></a></td>
            <td><span class="badge">{{ $p->market }}</span></td>
            <td>
                {{ rtrim(rtrim($p->quantity, '0'), '.') }}
                @if($p->hasDrift())
                    <span class="badge badge-orange" title="IBKR reports {{ rtrim(rtrim($p->broker_quantity, '0'), '.') }}">drift</span>
                @endif
            </td>
            <td>{{ $p->currency }} {{ number_format((float)$p->avg_buy_price, 2) }}</td>
            <td>
                {{ $p->current_price !== null ? $p->currency.' '.number_format($p->current_price, 2) : '—' }}
                @if($p->price_is_stale && $p->current_price !== null)
                    <span class="badge" title="Older than {{ $maxPriceAgeMinutes }} minutes">stale</span>
                @endif
            </td>
            <td>
                @if($p->gain_pct !== null)
                    <span class="{{ $p->gain_pct >= 0 ? 'gain' : 'loss' }}">{{ sprintf('%+.2f', $p->gain_pct) }}%</span>
                @else —
                @endif
            </td>
            <td>@include('partials.rule-badge', ['position' => $p, 'globalRule' => $globalRule])</td>
            <td><a href="/positions/{{ $p->id }}" class="btn btn-sm">View</a></td>
        </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

@if($recentOrders->isNotEmpty())
<h2>Recent orders</h2>
<div class="card" style="padding:0">
    <table>
        <thead><tr><th>Symbol</th><th>Side</th><th>Qty</th><th>Status</th><th>When</th></tr></thead>
        <tbody>
        @foreach($recentOrders as $o)
        <tr>
            <td>{{ $o->symbol ?? '—' }}</td>
            <td><span class="badge {{ $o->side === 'buy' ? 'badge-green' : 'badge-red' }}">{{ strtoupper($o->side) }}</span></td>
            <td>{{ rtrim(rtrim($o->quantity, '0'), '.') }}</td>
            <td>
                @php $cls = match($o->status) { 'filled'=>'badge-green','failed'=>'badge-red','placed'=>'badge-blue','simulated'=>'badge-orange','unreconciled'=>'badge-orange', default=>'' } @endphp
                <span class="badge {{ $cls }}">{{ match($o->status) { 'simulated' => 'simulated (dry run)', 'unreconciled' => 'needs review', default => $o->status } }}</span>
            </td>
            <td style="color:var(--muted)">{{ $o->created_at->diffForHumans() }}</td>
        </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif
@endsection
