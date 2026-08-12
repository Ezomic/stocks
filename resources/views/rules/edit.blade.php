@extends('layout')
@section('title', 'Edit Rule')
@section('content')
<div class="page-header">
    <h1>Edit rule{{ $rule->position ? ' — '.$rule->position->symbol : ' (global)' }}</h1>
    <a href="/rules" class="btn">Cancel</a>
</div>
<div class="card" style="max-width:480px">
    <form method="POST" action="/rules/{{ $rule->id }}">
        @csrf @method('PUT')
        <div class="form-group">
            <label>Position</label>
            <select name="position_id">
                <option value="">— Global default —</option>
                @foreach($positions as $p)
                    <option value="{{ $p->id }}" @selected(old('position_id', $rule->position_id) == $p->id)>{{ $p->symbol }} ({{ $p->account_mode }})</option>
                @endforeach
            </select>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
            <div class="form-group">
                <label>Take profit %</label>
                <input type="number" name="take_profit_pct" value="{{ old('take_profit_pct', $rule->take_profit_pct) }}" step="0.01">
            </div>
            <div class="form-group">
                <label>Stop loss %</label>
                <input type="number" name="stop_loss_pct" value="{{ old('stop_loss_pct', $rule->stop_loss_pct) }}" step="0.01">
            </div>
        </div>
        <div class="form-group">
            <label>Stop loss measured from</label>
            <select name="stop_loss_type">
                <option value="entry" @selected(old('stop_loss_type', $rule->stop_loss_type) === 'entry')>Entry price (fixed)</option>
                <option value="trailing" @selected(old('stop_loss_type', $rule->stop_loss_type) === 'trailing')>Highest price seen (trailing)</option>
            </select>
            <span style="color:var(--muted);font-size:12px">A trailing stop follows the price up and fires on a fall from the peak, not from what you paid.</span>
        </div>
        <div class="form-group">
            <label>Cooldown (minutes)</label>
            <input type="number" name="cooldown_minutes" value="{{ old('cooldown_minutes', $rule->cooldown_minutes) }}" min="1" required>
        </div>
        <div class="form-group" style="display:flex;align-items:center;gap:10px">
            <input type="checkbox" name="is_active" value="1" id="is_active" @checked(old('is_active', $rule->is_active)) style="width:auto">
            <label for="is_active" style="margin:0">Active</label>
        </div>
        <button type="submit" class="btn btn-primary">Save changes</button>
    </form>
</div>
@endsection
