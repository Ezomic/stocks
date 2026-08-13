@extends('layout')
@section('title', 'Watchlist')
@section('content')
<div class="page-header">
    <h1>Watchlist</h1>
</div>

<div class="card">
    <h2 style="margin-top:0">Find a contract</h2>
    <p style="font-size:13px;color:var(--muted);margin-top:0">
        Searches IBKR for the contract id. The same id is what a manually added position needs,
        so this works as a lookup even if you never add anything to the watchlist.
    </p>
    <form method="GET" action="{{ route('watchlist.index') }}" style="display:flex;gap:8px;align-items:flex-start">
        <input type="text" name="symbol" value="{{ $query }}" placeholder="e.g. AAPL" style="flex:1">
        <select name="secType" style="width:140px">
            <option value="STK" @selected(request('secType') === 'STK')>Stock</option>
            <option value="CRYPTO" @selected(request('secType') === 'CRYPTO')>Crypto</option>
        </select>
        <button type="submit" class="btn">Search</button>
    </form>

    @if($query !== '' && $results === [])
        <p style="font-size:13px;color:var(--muted);margin-bottom:0">
            No contracts came back for "{{ $query }}". The gateway also has to be authenticated for a search to work.
        </p>
    @endif

    @if($results !== [])
    <div style="margin-top:16px;border-top:1px solid var(--border)">
        @foreach($results as $result)
        <form method="POST" action="{{ route('watchlist.store') }}"
              style="display:flex;gap:12px;align-items:center;padding:10px 0;border-bottom:1px solid var(--border)">
            @csrf
            <input type="hidden" name="symbol" value="{{ $result['symbol'] }}">
            <input type="hidden" name="ibkr_con_id" value="{{ $result['conid'] }}">
            <input type="hidden" name="market" value="{{ request('secType', 'STK') }}">
            <div style="flex:1;min-width:0">
                <strong>{{ $result['symbol'] }}</strong>
                <span style="color:var(--muted);font-size:12px"> {{ $result['description'] }} {{ $result['exchange'] }}</span>
                <div style="font-size:12px;color:var(--muted)">conid <code>{{ $result['conid'] }}</code></div>
            </div>
            <select name="currency" style="width:90px">
                @foreach(['USD', 'EUR', 'GBP'] as $currency)
                    <option value="{{ $currency }}">{{ $currency }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-sm">Watch</button>
        </form>
        @endforeach
    </div>
    @endif
</div>

@error('ibkr_con_id')
    <div class="alert alert-error">{{ $message }}</div>
@enderror

@if($items->isEmpty())
    <div class="card" style="color:var(--muted);text-align:center;padding:40px;">
        Nothing on the watchlist yet. Search above to add a symbol you do not hold.
    </div>
@else
<div class="card" style="padding:0">
    <table>
        <thead><tr><th>Symbol</th><th>Market</th><th>Last price</th><th>Seen</th><th>Conid</th><th></th></tr></thead>
        <tbody>
        @foreach($items as $item)
        <tr>
            <td><strong>{{ $item->symbol }}</strong></td>
            <td><span class="badge">{{ $item->market }}</span></td>
            <td>
                @if($item->latestSnapshot)
                    {{ $item->currency }} {{ number_format((float) $item->latestSnapshot->price, 2) }}
                @else
                    <span style="color:var(--muted)">not priced yet</span>
                @endif
            </td>
            <td style="color:var(--muted)">{{ $item->latestSnapshot?->fetched_at->diffForHumans() ?? '—' }}</td>
            <td style="color:var(--muted);font-size:12px"><code>{{ $item->ibkr_con_id }}</code></td>
            <td>
                <form method="POST" action="{{ route('watchlist.destroy', $item) }}" style="margin:0"
                      onsubmit="return confirm('Remove {{ $item->symbol }} from the watchlist?')">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-sm btn-danger">Remove</button>
                </form>
            </td>
        </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif
@endsection
