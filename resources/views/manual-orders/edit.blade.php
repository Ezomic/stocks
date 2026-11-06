@extends('layout')
@section('title', 'Edit order')
@section('content')
<div class="page-header">
    <h1>Edit order</h1>
    <a href="{{ route('manual-orders.index') }}" class="btn">Cancel</a>
</div>
<div class="card" style="max-width:560px">
    <p style="font-size:13px;color:var(--muted);margin-top:0">
        This is a record only. Nothing is sent to the broker.
    </p>
    <form method="POST" action="{{ route('manual-orders.update', $order) }}">
        @method('PUT')
        @include('manual-orders._form')
    </form>
</div>
@endsection
