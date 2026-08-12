@extends('layout')
@section('title', $position->symbol)
@section('content')
<div class="page-header">
    <h1>{{ $position->symbol }} <span style="font-weight:400;color:var(--muted);font-size:14px">{{ $position->market }}</span></h1>
    <div style="display:flex;gap:8px">
        <a href="/positions/{{ $position->id }}/edit" class="btn">Edit</a>
        <a href="/positions" class="btn">Back</a>
    </div>
</div>

<div class="stats-row">
    <div class="stat">
        <div class="stat-label">Current price</div>
        <div class="stat-value">{{ $currentPrice ? $position->currency.' '.number_format((float)$currentPrice, 2) : '—' }}</div>
    </div>
    <div class="stat">
        <div class="stat-label">Gain / Loss</div>
        <div class="stat-value @if($gainPct !== null && $gainPct >= 0) gain @elseif($gainPct !== null) loss @endif">
            {{ $gainPct !== null ? sprintf('%+.2f', $gainPct).'%' : '—' }}
        </div>
    </div>
    <div class="stat">
        <div class="stat-label">Quantity</div>
        <div class="stat-value">{{ rtrim(rtrim($position->quantity, '0'), '.') }}</div>
    </div>
    <div class="stat">
        <div class="stat-label">Avg buy price</div>
        <div class="stat-value">{{ $position->currency }} {{ number_format((float)$position->avg_buy_price, 2) }}</div>
    </div>
</div>

@if($snapshots->count() > 1)
<div class="card">
    <h2>Price history (last 24h)</h2>
    @php
        $prices = $snapshots->pluck('price')->map(fn($p) => (float)$p);
        $min = $prices->min(); $max = $prices->max();
        $range = $max - $min ?: 1;
        $w = 800; $h = 80;
        $points = $prices->values()->map(function($p, $i) use ($prices, $min, $range, $w, $h) {
            $x = $i / max($prices->count()-1, 1) * $w;
            $y = $h - (($p - $min) / $range * ($h - 10)) - 5;
            return "$x,$y";
        })->implode(' ');
        $last = $prices->last(); $first = $prices->first();
        $color = $last >= $first ? '#34c77b' : '#f25757';
    @endphp
    <svg viewBox="0 0 {{ $w }} {{ $h }}" style="width:100%;height:80px;display:block">
        <polyline points="{{ $points }}" fill="none" stroke="{{ $color }}" stroke-width="1.5"/>
    </svg>
</div>
@endif

<div class="page-header" style="margin-top:8px">
    <h2 style="margin:0">Rules</h2>
    <a href="/rules/create?position_id={{ $position->id }}" class="btn btn-sm">+ Add rule</a>
</div>
@if($position->rule)
<div class="card">
    <div style="display:flex;gap:24px;align-items:center">
        <div><span style="color:var(--muted);font-size:12px">Take profit</span><br><strong>{{ $position->rule->take_profit_pct ?? '—' }}%</strong></div>
        <div><span style="color:var(--muted);font-size:12px">Stop loss</span><br><strong>{{ $position->rule->stop_loss_pct ?? '—' }}%</strong></div>
        <div><span style="color:var(--muted);font-size:12px">Cooldown</span><br><strong>{{ $position->rule->cooldown_minutes }}min</strong></div>
        <div><span style="color:var(--muted);font-size:12px">Status</span><br>
            <span class="badge {{ $position->rule->is_active ? 'badge-green' : '' }}">{{ $position->rule->is_active ? 'Active' : 'Paused' }}</span>
        </div>
        @if($position->last_triggered_at)
        <div><span style="color:var(--muted);font-size:12px">Last triggered</span><br>{{ $position->last_triggered_at->diffForHumans() }}</div>
        @endif
        @if($position->rule->isTrailing())
        <div><span style="color:var(--muted);font-size:12px">Type</span><br><span class="badge badge-blue">Trailing</span></div>
        @endif
        <div style="margin-left:auto">
            <a href="/rules/{{ $position->rule->id }}/edit" class="btn btn-sm">Edit rule</a>
        </div>
    </div>
</div>
@else
<div class="card" style="color:var(--muted);font-size:13px">No rule set for this position. <a href="/rules/create?position_id={{ $position->id }}">Add one</a>.</div>
@endif

@if($governingRule)
<div class="card">
    <div style="display:flex;gap:24px;align-items:center;flex-wrap:wrap">
        <div>
            <span style="color:var(--muted);font-size:12px">Sells at (take profit)</span><br>
            <strong>{{ $takeProfitPrice !== null ? $position->currency.' '.number_format($takeProfitPrice, 2) : '—' }}</strong>
        </div>
        <div>
            <span style="color:var(--muted);font-size:12px">Sells at (stop loss)</span><br>
            <strong>{{ $stopLossPrice !== null ? $position->currency.' '.number_format($stopLossPrice, 2) : '—' }}</strong>
        </div>
        @if($governingRule->isTrailing())
        <div>
            <span style="color:var(--muted);font-size:12px">Peak price</span><br>
            <strong>{{ $peakPrice !== null ? $position->currency.' '.number_format($peakPrice, 2) : '—' }}</strong>
        </div>
        <div style="color:var(--muted);font-size:12px;max-width:280px">
            The trailing stop follows this peak. It is the highest price on record
            @if($peakSince) since {{ $peakSince->format('d M') }} @endif,
            which is as far back as price history is kept, not necessarily since you bought.
        </div>
        @endif
    </div>
</div>
@endif

<div class="page-header" style="margin-top:20px">
    <h2 style="margin:0">Order history</h2>
</div>
@if($orders->isEmpty())
    <div class="card" style="color:var(--muted);font-size:13px">No orders yet.</div>
@else
<div class="card" style="padding:0">
    <table>
        <thead><tr><th>Side</th><th>Qty</th><th>Type</th><th>Status</th><th>Placed</th><th>Filled</th><th>Fill price</th><th></th></tr></thead>
        <tbody>
        @foreach($orders as $o)
        <tr>
            <td><span class="badge {{ $o->side === 'buy' ? 'badge-green' : 'badge-red' }}">{{ strtoupper($o->side) }}</span></td>
            <td>{{ rtrim(rtrim($o->quantity, '0'), '.') }}</td>
            <td>{{ strtoupper($o->order_type) }}</td>
            <td>
                @php $cls = match($o->status) { 'filled'=>'badge-green','failed'=>'badge-red','placed'=>'badge-blue','simulated'=>'badge-orange','unreconciled'=>'badge-orange', default=>'' } @endphp
                <span class="badge {{ $cls }}">{{ match($o->status) { 'simulated' => 'simulated (dry run)', 'unreconciled' => 'needs review', default => $o->status } }}</span>
                @if($o->status === 'placed' && $o->cancel_requested_at)
                    <span class="badge badge-orange">cancelling</span>
                @endif
            </td>
            <td style="color:var(--muted)">{{ $o->placed_at?->format('d M H:i') ?? '—' }}</td>
            <td style="color:var(--muted)">{{ $o->filled_at?->format('d M H:i') ?? '—' }}</td>
            <td>{{ $o->fill_price ? $position->currency.' '.number_format((float)$o->fill_price, 2) : '—' }}</td>
            <td>
                @if($o->status === 'placed' && $o->broker_order_id && ! $o->cancel_requested_at)
                <form method="POST" action="{{ route('orders.cancel', $o) }}" style="margin:0"
                      onsubmit="return confirm('Ask IBKR to cancel this order?')">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-danger">Cancel</button>
                </form>
                @endif
            </td>
        </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif
@endsection
