@extends('layout')
@section('title', 'Settings')
@section('content')
<h1>Settings</h1>

<div class="card" style="max-width:600px">
    <h2>Automated trading</h2>
    <div style="display:flex;align-items:center;gap:16px">
        <span class="badge {{ $tradingEnabled ? 'badge-green' : 'badge-red' }}">
            {{ $tradingEnabled ? 'Running' : 'Paused' }}
        </span>
        <p style="font-size:13px;color:var(--muted);margin:0;flex:1">
            @if($tradingEnabled)
                Rules are evaluated every minute and orders are placed automatically.
            @else
                No orders will be placed. Prices and order statuses keep syncing.
            @endif
        </p>
        <form method="POST" action="{{ route('settings.trading') }}" style="margin:0">
            @csrf
            <input type="hidden" name="trading_enabled" value="{{ $tradingEnabled ? '0' : '1' }}">
            <button type="submit" class="btn {{ $tradingEnabled ? 'btn-danger' : 'btn-primary' }}"
                    @if($tradingEnabled) onclick="return confirm('Pause automated trading? No orders will be placed until you resume.')" @endif>
                {{ $tradingEnabled ? 'Pause trading' : 'Resume trading' }}
            </button>
        </form>
    </div>
</div>

<div class="card" style="max-width:600px">
    <h2>IBKR connection</h2>
    <table style="margin-bottom:0">
        <tr><td style="color:var(--muted);width:160px">Mode</td><td><span class="badge {{ $mode === 'paper' ? 'badge-orange' : 'badge-red' }}">{{ strtoupper($mode) }}</span></td></tr>
        <tr><td style="color:var(--muted)">Paper gateway</td><td>{{ $paperGatewayUrl }}</td></tr>
        <tr><td style="color:var(--muted)">Paper account</td><td>{{ $paperAccountId ?: '—' }}</td></tr>
        <tr><td style="color:var(--muted)">Live gateway</td><td>{{ $liveGatewayUrl }}</td></tr>
        <tr><td style="color:var(--muted)">Live account</td><td>{{ $liveAccountId ?: '—' }}</td></tr>
    </table>
    <p style="font-size:12px;color:var(--muted);margin-top:12px">To change mode or credentials, edit <code>.env</code> and restart the scheduler.</p>
</div>

<div class="card" style="max-width:600px">
    <h2>Manual triggers</h2>
    <div style="display:flex;flex-direction:column;gap:12px">
        <form method="POST" action="/settings/sync-prices">
            @csrf
            <button type="submit" class="btn">Sync prices now</button>
            <span style="color:var(--muted);font-size:12px;margin-left:10px">Fetch latest prices from IBKR</span>
        </form>
        <form method="POST" action="/settings/evaluate-rules">
            @csrf
            <button type="submit" class="btn">Evaluate rules now</button>
            <span style="color:var(--muted);font-size:12px;margin-left:10px">Check all thresholds against current prices</span>
        </form>
        <form method="POST" action="/settings/sync-orders">
            @csrf
            <button type="submit" class="btn">Sync order status now</button>
            <span style="color:var(--muted);font-size:12px;margin-left:10px">Update placed order statuses from IBKR</span>
        </form>
    </div>
</div>

<div class="card" style="max-width:600px">
    <h2>API tokens</h2>
    <p style="font-size:13px;color:var(--muted);margin-top:0">Personal access tokens authenticate requests to the API. Treat them like passwords.</p>

    @if(session('createdToken'))
        <div class="alert alert-success" style="display:flex;flex-direction:column;gap:8px;align-items:flex-start">
            <strong>Token "{{ session('createdToken')['name'] }}" created</strong>
            <span style="font-size:12px">Copy it now. You will not be able to see it again.</span>
            <code style="width:100%;overflow-x:auto;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:8px;font-size:12px">{{ session('createdToken')['plainText'] }}</code>
        </div>
    @endif

    <form method="POST" action="{{ route('api-tokens.store') }}" style="display:flex;gap:8px;align-items:flex-start;margin-bottom:16px">
        @csrf
        <div style="flex:1">
            <input type="text" name="name" placeholder="e.g. CI pipeline" value="{{ old('name') }}" autocomplete="off" style="width:100%">
            @error('name')
                <span style="color:var(--red);font-size:12px">{{ $message }}</span>
            @enderror
        </div>
        <button type="submit" class="btn">Create token</button>
    </form>

    @forelse($tokens as $token)
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;padding:10px 0;border-top:1px solid var(--border)">
            <div style="min-width:0">
                <div style="font-weight:500">{{ $token['name'] }}</div>
                <div style="font-size:12px;color:var(--muted)">
                    Created {{ $token['createdAtDiff'] }} ·
                    {{ $token['lastUsedAtDiff'] ? 'last used '.$token['lastUsedAtDiff'] : 'never used' }}
                </div>
            </div>
            <form method="POST" action="{{ route('api-tokens.destroy', $token['id']) }}"
                  onsubmit="return confirm('Revoke the token &quot;{{ $token['name'] }}&quot;? Anything using it will stop working.')">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger">Revoke</button>
            </form>
        </div>
    @empty
        <p style="font-size:13px;color:var(--muted);border-top:1px solid var(--border);padding-top:12px;margin-bottom:0">No API tokens yet.</p>
    @endforelse
</div>

<div class="card" style="max-width:600px">
    <h2>Crontab</h2>
    <p style="font-size:13px;color:var(--muted)">Add this to your crontab (<code>crontab -e</code>) to enable the scheduler:</p>
    <pre style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:12px;font-size:12px;overflow-x:auto">* * * * * cd {{ base_path() }} &amp;&amp; php artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</pre>
</div>
@endsection
