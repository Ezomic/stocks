@extends('layout')
@section('title', 'Trade history')
@section('content')
<div class="page-header">
    <h1>Trade history</h1>
</div>

<div class="card" style="font-size:13px;color:var(--muted)">
    Realised figures are average-cost, not FIFO. Positions carry a single average buy price
    rather than individual purchase lots, so on a holding that was sold in parts these numbers
    will not match your broker statement or a tax calculation.
</div>

@if(count($totals) > 0)
<div class="stats-row">
    @foreach($totals as $total)
    <div class="stat">
        <div class="stat-label">Realised ({{ $total['currency'] }}) &middot; {{ $total['trades'] }} {{ Str::plural('trade', $total['trades']) }}</div>
        <div class="stat-value {{ $total['realised'] >= 0 ? 'gain' : 'loss' }}">
            {{ $total['currency'] }} {{ number_format($total['realised'], 2) }}
        </div>
    </div>
    @endforeach
</div>
@endif

@if($trades->isEmpty())
    <div class="card" style="color:var(--muted);text-align:center;padding:40px;">No closed trades yet.</div>
@else
<div class="card" style="padding:0">
    <table>
        <thead><tr>
            <th>Symbol</th><th>Qty</th><th>Avg cost</th><th>Sold at</th><th>Realised</th><th>Filled</th>
        </tr></thead>
        <tbody>
        @foreach($trades as $t)
        @php $realised = $t->realisedProfit(); @endphp
        <tr>
            <td>
                @if($t->position)
                    <a href="/positions/{{ $t->position_id }}"><strong>{{ $t->symbol }}</strong></a>
                @else
                    <strong>{{ $t->symbol ?? '—' }}</strong>
                    <span class="badge" title="The position has been deleted">deleted</span>
                @endif
            </td>
            <td>{{ rtrim(rtrim($t->quantity, '0'), '.') }}</td>
            <td>{{ $t->currency }} {{ number_format((float) $t->cost_basis, 2) }}</td>
            <td>{{ $t->currency }} {{ number_format((float) $t->fill_price, 2) }}</td>
            <td class="{{ $realised !== null && $realised >= 0 ? 'gain' : 'loss' }}">
                {{ $realised !== null ? sprintf('%+.2f', $realised) : '—' }}
            </td>
            <td style="color:var(--muted)">{{ $t->filled_at?->format('d M Y H:i') ?? '—' }}</td>
        </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif
@endsection
