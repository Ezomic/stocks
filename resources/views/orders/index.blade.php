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
            <th>Status</th><th>Broker ID</th><th>Placed</th><th>Filled</th><th>Fill price</th><th></th>
        </tr></thead>
        <tbody>
        @foreach($orders as $o)
        <tr>
            <td>
                @if($o->position)
                    <a href="/positions/{{ $o->position_id }}">{{ $o->symbol }}</a>
                @else
                    {{ $o->symbol ?? '—' }}
                    <span class="badge" title="The position this order belonged to has been deleted">deleted</span>
                @endif
            </td>
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
            <td style="color:var(--muted);font-size:12px">{{ $o->broker_order_id ?? '—' }}</td>
            <td style="color:var(--muted)">{{ $o->placed_at?->format('d M H:i') ?? '—' }}</td>
            <td style="color:var(--muted)">{{ $o->filled_at?->format('d M H:i') ?? '—' }}</td>
            <td>{{ $o->fill_price ? trim(($o->position->currency ?? '').' '.number_format((float)$o->fill_price, 2)) : '—' }}</td>
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
{{ $orders->links() }}
@endif
@endsection
