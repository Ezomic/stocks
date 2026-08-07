@extends('layout')
@section('title', 'Orders')
@section('content')
<div class="page-header">
    <h1>Orders</h1>
</div>
@if($orders->isEmpty())
    <div class="card" style="color:var(--muted);text-align:center;padding:40px;">No orders yet.</div>
@else
<div class="card" style="padding:0">
    <table>
        <thead><tr>
            <th>Symbol</th><th>Side</th><th>Qty</th><th>Type</th>
            <th>Status</th><th>Broker ID</th><th>Placed</th><th>Filled</th><th>Fill price</th>
        </tr></thead>
        <tbody>
        @foreach($orders as $o)
        <tr>
            <td><a href="/positions/{{ $o->position_id }}">{{ $o->position->symbol }}</a></td>
            <td><span class="badge {{ $o->side === 'buy' ? 'badge-green' : 'badge-red' }}">{{ strtoupper($o->side) }}</span></td>
            <td>{{ rtrim(rtrim($o->quantity, '0'), '.') }}</td>
            <td>{{ strtoupper($o->order_type) }}</td>
            <td>
                @php $cls = match($o->status) { 'filled'=>'badge-green','failed'=>'badge-red','placed'=>'badge-blue','simulated'=>'badge-orange','unreconciled'=>'badge-orange', default=>'' } @endphp
                <span class="badge {{ $cls }}">{{ match($o->status) { 'simulated' => 'simulated (dry run)', 'unreconciled' => 'needs review', default => $o->status } }}</span>
            </td>
            <td style="color:var(--muted);font-size:12px">{{ $o->broker_order_id ?? '—' }}</td>
            <td style="color:var(--muted)">{{ $o->placed_at?->format('d M H:i') ?? '—' }}</td>
            <td style="color:var(--muted)">{{ $o->filled_at?->format('d M H:i') ?? '—' }}</td>
            <td>{{ $o->fill_price ? $o->position->currency.' '.number_format((float)$o->fill_price, 2) : '—' }}</td>
        </tr>
        @endforeach
        </tbody>
    </table>
</div>
{{ $orders->links() }}
@endif
@endsection
