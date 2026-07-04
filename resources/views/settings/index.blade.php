@extends('layout')
@section('title', 'Settings')
@section('content')
<h1>Settings</h1>

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
    <h2>Crontab</h2>
    <p style="font-size:13px;color:var(--muted)">Add this to your crontab (<code>crontab -e</code>) to enable the scheduler:</p>
    <pre style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:12px;font-size:12px;overflow-x:auto">* * * * * cd {{ base_path() }} &amp;&amp; php artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</pre>
</div>
@endsection
