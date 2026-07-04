@extends('layout')
@section('title', 'Edit Position')
@section('content')
<div class="page-header">
    <h1>Edit {{ $position->symbol }}</h1>
    <a href="/positions/{{ $position->id }}" class="btn">Cancel</a>
</div>
<div class="card" style="max-width:540px">
    <form method="POST" action="/positions/{{ $position->id }}">
        @csrf @method('PUT')
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
            <div class="form-group">
                <label>Symbol</label>
                <input type="text" name="symbol" value="{{ old('symbol', $position->symbol) }}" required>
            </div>
            <div class="form-group">
                <label>Market</label>
                <select name="market">
                    <option value="STK" @selected(old('market',$position->market)==='STK')>STK — Stock</option>
                    <option value="ETF" @selected(old('market',$position->market)==='ETF')>ETF</option>
                    <option value="CRYPTO" @selected(old('market',$position->market)==='CRYPTO')>CRYPTO</option>
                </select>
            </div>
            <div class="form-group">
                <label>Quantity</label>
                <input type="number" name="quantity" value="{{ old('quantity', $position->quantity) }}" step="0.000001" required>
            </div>
            <div class="form-group">
                <label>Avg buy price</label>
                <input type="number" name="avg_buy_price" value="{{ old('avg_buy_price', $position->avg_buy_price) }}" step="0.0001" required>
            </div>
            <div class="form-group">
                <label>Currency</label>
                <input type="text" name="currency" value="{{ old('currency', $position->currency) }}" maxlength="3" required>
            </div>
            <div class="form-group">
                <label>Account mode</label>
                <select name="account_mode">
                    <option value="paper" @selected(old('account_mode',$position->account_mode)==='paper')>Paper</option>
                    <option value="live" @selected(old('account_mode',$position->account_mode)==='live')>Live</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label>Broker account ID</label>
            <input type="text" name="broker_account_id" value="{{ old('broker_account_id', $position->broker_account_id) }}" required>
        </div>
        <div class="form-group">
            <label>IBKR contract ID (conid)</label>
            <input type="text" name="ibkr_con_id" value="{{ old('ibkr_con_id', $position->ibkr_con_id) }}">
        </div>
        <div class="form-group">
            <label>Notes</label>
            <textarea name="notes">{{ old('notes', $position->notes) }}</textarea>
        </div>
        <button type="submit" class="btn btn-primary">Save changes</button>
    </form>
</div>
@endsection
