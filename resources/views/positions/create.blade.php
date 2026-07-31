@extends('layout')
@section('title', 'Add Position')
@section('content')
<div class="page-header">
    <h1>Add position</h1>
    <a href="/positions" class="btn">Cancel</a>
</div>
<div class="card" style="max-width:540px">
    <form method="POST" action="/positions">
        @csrf
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
            <div class="form-group">
                <label>Symbol</label>
                <input type="text" name="symbol" value="{{ old('symbol') }}" placeholder="AAPL" style="text-transform:uppercase" required>
                @error('symbol') <div class="error-text">{{ $message }}</div> @enderror
            </div>
            <div class="form-group">
                <label>Market</label>
                <select name="market">
                    <option value="STK" @selected(old('market','STK')==='STK')>STK — Stock</option>
                    <option value="ETF" @selected(old('market')==='ETF')>ETF</option>
                    <option value="CRYPTO" @selected(old('market')==='CRYPTO')>CRYPTO</option>
                </select>
            </div>
            <div class="form-group">
                <label>Quantity</label>
                <input type="number" name="quantity" value="{{ old('quantity') }}" step="0.000001" required>
                @error('quantity') <div class="error-text">{{ $message }}</div> @enderror
            </div>
            <div class="form-group">
                <label>Avg buy price</label>
                <input type="number" name="avg_buy_price" value="{{ old('avg_buy_price') }}" step="0.0001" required>
                @error('avg_buy_price') <div class="error-text">{{ $message }}</div> @enderror
            </div>
            <div class="form-group">
                <label>Currency</label>
                <input type="text" name="currency" value="{{ old('currency','USD') }}" maxlength="3" required>
            </div>
            <div class="form-group">
                <label>Account mode</label>
                <select name="account_mode">
                    <option value="paper" @selected(old('account_mode','paper')==='paper')>Paper</option>
                    <option value="live" @selected(old('account_mode')==='live')>Live</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label>Broker account ID</label>
            <input type="text" name="broker_account_id" value="{{ old('broker_account_id', config('ibkr.'.config('ibkr.mode').'.account_id')) }}" required>
        </div>
        <div class="form-group">
            <label>IBKR contract ID (conid) <span style="color:var(--muted)">— required for price syncing</span></label>
            <input type="text" name="ibkr_con_id" value="{{ old('ibkr_con_id') }}" placeholder="e.g. 265598">
        </div>
        <div class="form-group">
            <label>Notes</label>
            <textarea name="notes">{{ old('notes') }}</textarea>
        </div>
        <button type="submit" class="btn btn-primary">Save position</button>
    </form>
</div>
@endsection
