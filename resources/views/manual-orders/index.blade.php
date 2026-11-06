@extends('layout')
@section('title', 'Order log')
@section('content')
<div class="page-header">
    <h1>Order log</h1>
    <a href="{{ route('manual-orders.create') }}" class="btn btn-primary">Log order</a>
</div>
<p style="font-size:13px;color:var(--muted);margin-top:0">
    Orders you placed in IBKR yourself and logged here. This list is a record only and sends nothing to the broker.
</p>
<form method="GET" style="margin-bottom:16px">
    <select name="status" onchange="this.form.submit()" style="width:auto">
        <option value="">All statuses</option>
        @foreach(\App\Models\ManualOrder::STATUSES as $s)
            <option value="{{ $s }}" @selected($status === $s)>{{ ucfirst($s) }}</option>
        @endforeach
    </select>
</form>
@if($orders->isEmpty())
    <div class="card" style="color:var(--muted);text-align:center;padding:40px;">No logged orders yet.</div>
@else
<div class="card" style="padding:0;overflow-x:auto">
    <table>
        <thead><tr>
            <th>Date</th><th>Symbol</th><th>Side</th><th>Qty</th><th>Price</th><th>Fees</th><th>Status</th><th>Note</th><th></th>
        </tr></thead>
        <tbody>
        @foreach($orders as $o)
        <tr>
            <td style="color:var(--muted)">{{ $o->order_date->format('d M Y') }}</td>
            <td>
                @if($o->position_id)
                    <a href="{{ route('positions.show', $o->position_id) }}">{{ $o->symbol }}</a>
                @else
                    {{ $o->symbol }}
                @endif
            </td>
            <td><span class="badge {{ $o->side === 'buy' ? 'badge-green' : 'badge-red' }}">{{ strtoupper($o->side) }}</span></td>
            <td>{{ rtrim(rtrim($o->quantity, '0'), '.') }}</td>
            <td>{{ $o->price !== null ? $o->currency.' '.number_format((float) $o->price, 2) : '—' }}</td>
            <td style="color:var(--muted)">{{ number_format((float) $o->fees, 2) }}</td>
            <td>
                @php $cls = match($o->status) { 'filled' => 'badge-green', 'cancelled' => 'badge-red', default => 'badge-blue' } @endphp
                <span class="badge {{ $cls }}">{{ $o->status }}</span>
            </td>
            <td style="color:var(--muted);font-size:12px;max-width:240px">{{ $o->note ?? '—' }}</td>
            <td><a href="{{ route('manual-orders.edit', $o) }}" class="btn btn-sm">Edit</a></td>
        </tr>
        @endforeach
        </tbody>
    </table>
</div>
<div style="margin-top:16px">{{ $orders->links() }}</div>
@endif
@endsection
