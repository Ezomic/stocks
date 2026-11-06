@extends('layout')
@section('title', 'Log order')
@section('content')
<div class="page-header">
    <h1>Log order</h1>
    <a href="{{ route('manual-orders.index') }}" class="btn">Cancel</a>
</div>
<div class="card" style="max-width:560px">
    <p style="font-size:13px;color:var(--muted);margin-top:0">
        This is a record only. Place the real order in IBKR yourself, then log it here. Nothing is sent to the broker.
    </p>
    <form method="POST" action="{{ route('manual-orders.store') }}">
        @include('manual-orders._form')
    </form>
</div>
@endsection
