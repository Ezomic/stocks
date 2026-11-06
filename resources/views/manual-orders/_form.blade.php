@csrf
<div class="form-group">
    <label for="position_id">Position <span style="color:var(--muted)">(required for a sell, optional for a buy)</span></label>
    <select name="position_id" id="position_id">
        <option value="">— Not held yet —</option>
        @foreach($positions as $p)
            <option value="{{ $p->id }}" @selected((string) old('position_id', $order->position_id) === (string) $p->id)>{{ $p->symbol }} ({{ $p->account_mode }}, {{ rtrim(rtrim($p->quantity, '0'), '.') ?: '0' }} held)</option>
        @endforeach
    </select>
    @error('position_id')<span style="color:var(--red);font-size:12px">{{ $message }}</span>@enderror
</div>
<div class="form-group">
    <label for="symbol">Symbol <span style="color:var(--muted)">(only when no position is chosen)</span></label>
    <input type="text" name="symbol" id="symbol" value="{{ old('symbol', $order->position_id ? '' : $order->symbol) }}" placeholder="e.g. AAPL">
    @error('symbol')<span style="color:var(--red);font-size:12px">{{ $message }}</span>@enderror
</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
    <div class="form-group">
        <label for="side">Side</label>
        <select name="side" id="side">
            @foreach(['buy', 'sell'] as $side)
                <option value="{{ $side }}" @selected(old('side', $order->side) === $side)>{{ ucfirst($side) }}</option>
            @endforeach
        </select>
        @error('side')<span style="color:var(--red);font-size:12px">{{ $message }}</span>@enderror
    </div>
    <div class="form-group">
        <label for="status">Status</label>
        <select name="status" id="status">
            @foreach(\App\Models\ManualOrder::STATUSES as $status)
                <option value="{{ $status }}" @selected(old('status', $order->status) === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
        @error('status')<span style="color:var(--red);font-size:12px">{{ $message }}</span>@enderror
    </div>
</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
    <div class="form-group">
        <label for="quantity">Quantity</label>
        <input type="text" inputmode="decimal" name="quantity" id="quantity" value="{{ old('quantity', $order->quantity !== null ? rtrim(rtrim($order->quantity, '0'), '.') : '') }}">
        @error('quantity')<span style="color:var(--red);font-size:12px">{{ $message }}</span>@enderror
    </div>
    <div class="form-group">
        <label for="price">Price <span style="color:var(--muted)">(required once filled)</span></label>
        <input type="text" inputmode="decimal" name="price" id="price" value="{{ old('price', $order->price !== null ? rtrim(rtrim($order->price, '0'), '.') : '') }}">
        @error('price')<span style="color:var(--red);font-size:12px">{{ $message }}</span>@enderror
    </div>
</div>
<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px">
    <div class="form-group">
        <label for="fees">Fees</label>
        <input type="text" inputmode="decimal" name="fees" id="fees" value="{{ old('fees', rtrim(rtrim((string) $order->fees, '0'), '.') ?: '0') }}">
        @error('fees')<span style="color:var(--red);font-size:12px">{{ $message }}</span>@enderror
    </div>
    <div class="form-group">
        <label for="currency">Currency</label>
        <input type="text" name="currency" id="currency" maxlength="3" value="{{ old('currency', $order->currency) }}">
        @error('currency')<span style="color:var(--red);font-size:12px">{{ $message }}</span>@enderror
    </div>
    <div class="form-group">
        <label for="order_date">Date</label>
        <input type="date" name="order_date" id="order_date" value="{{ old('order_date', $order->order_date?->format('Y-m-d')) }}">
        @error('order_date')<span style="color:var(--red);font-size:12px">{{ $message }}</span>@enderror
    </div>
</div>
<div class="form-group">
    <label for="note">Note</label>
    <textarea name="note" id="note" rows="3">{{ old('note', $order->note) }}</textarea>
    @error('note')<span style="color:var(--red);font-size:12px">{{ $message }}</span>@enderror
</div>
<button type="submit" class="btn btn-primary">Save</button>
