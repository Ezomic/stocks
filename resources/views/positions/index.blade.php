@extends('layout')
@section('title', 'Positions')
@section('content')
<div class="page-header">
    <h1>Positions</h1>
    <div style="display:flex;gap:8px">
        <a href="{{ route('positions.export') }}" class="btn">Export CSV</a>
        <a href="/positions/create" class="btn btn-primary">+ Add position</a>
    </div>
</div>
@if($positions->isEmpty())
    <div class="card" style="color:var(--muted);text-align:center;padding:40px;">No positions yet.</div>
@else
<div class="card" style="padding:0">
    <table>
        <thead><tr>
            <th>Symbol</th><th>Mode</th><th>Market</th><th>Qty</th>
            <th>Avg buy</th><th>Current</th><th>Gain/Loss</th><th>Rule</th><th></th>
        </tr></thead>
        <tbody>
        @foreach($positions as $p)
        <tr>
            <td><a href="/positions/{{ $p->id }}"><strong>{{ $p->symbol }}</strong></a></td>
            <td><span class="badge {{ $p->account_mode === 'paper' ? 'badge-orange' : '' }}">{{ $p->account_mode }}</span></td>
            <td>{{ $p->market }}</td>
            <td>{{ rtrim(rtrim($p->quantity, '0'), '.') }}</td>
            <td>{{ $p->currency }} {{ number_format((float)$p->avg_buy_price, 2) }}</td>
            <td>{{ $p->current_price !== null ? $p->currency.' '.number_format($p->current_price, 2) : '—' }}</td>
            <td>
                @if($p->gain_pct !== null)
                    <span class="{{ $p->gain_pct >= 0 ? 'gain' : 'loss' }}">{{ sprintf('%+.2f', $p->gain_pct) }}%</span>
                @else ���
                @endif
            </td>
            <td>
                @if($p->rule)
                    <span class="badge badge-blue">TP/SL</span>
                @else
                    <a href="/rules/create?position_id={{ $p->id }}" class="badge" style="cursor:pointer">+ Rule</a>
                @endif
            </td>
            <td style="display:flex;gap:6px">
                <a href="/positions/{{ $p->id }}/edit" class="btn btn-sm">Edit</a>
                <form method="POST" action="/positions/{{ $p->id }}" style="margin:0">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this position? Its order history is kept and stays visible on the orders page.')">Delete</button>
                </form>
            </td>
        </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif
@endsection
