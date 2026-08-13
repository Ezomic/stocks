@extends('layout')
@section('title', 'Replay')
@section('content')
<div class="page-header">
    <h1>Replay on {{ $position->symbol }}</h1>
    <a href="/rules/create?position_id={{ $position->id }}" class="btn">Back to rule</a>
</div>

<div class="card" style="font-size:13px;color:var(--muted)">
    @if($result['snapshots'] === 0)
        There is no stored price history for {{ $position->symbol }}, so there is nothing to replay against.
    @else
        Replayed {{ number_format($result['snapshots']) }} stored prices from
        <strong>{{ $result['from']->format('d M Y H:i') }}</strong> to
        <strong>{{ $result['to']->format('d M Y H:i') }}</strong>.
        That is only the time this position was held and only as far back as prices are kept
        ({{ $retentionDays }} days), so it is a window, not a full backtest.
    @endif
</div>

<div class="card">
    <div style="display:flex;gap:24px;align-items:center;flex-wrap:wrap">
        <div><span style="color:var(--muted);font-size:12px">Take profit</span><br><strong>{{ $rule->take_profit_pct ?? '—' }}%</strong></div>
        <div><span style="color:var(--muted);font-size:12px">Stop loss</span><br><strong>{{ $rule->stop_loss_pct ?? '—' }}%</strong></div>
        <div><span style="color:var(--muted);font-size:12px">Measured from</span><br><strong>{{ $rule->isTrailing() ? 'Peak' : 'Entry' }}</strong></div>
        <div><span style="color:var(--muted);font-size:12px">Cooldown</span><br><strong>{{ $rule->cooldown_minutes }}min</strong></div>
        <div style="margin-left:auto">
            <span style="color:var(--muted);font-size:12px">Would have fired</span><br>
            <strong style="font-size:20px">{{ count($result['triggers']) }}</strong>
            {{ Str::plural('time', count($result['triggers'])) }}
        </div>
    </div>
</div>

@if($result['triggers'] !== [])
<div class="card" style="padding:0">
    <table>
        <thead><tr><th>When</th><th>Price</th><th>Threshold</th>@if($rule->isTrailing())<th>Peak at the time</th>@endif</tr></thead>
        <tbody>
        @foreach($result['triggers'] as $trigger)
        <tr>
            <td>{{ $trigger['at']->format('d M Y H:i') }}</td>
            <td>{{ $position->currency }} {{ number_format($trigger['price'], 2) }}</td>
            <td>
                <span class="badge {{ $trigger['threshold'] === 'take_profit' ? 'badge-green' : 'badge-red' }}">
                    {{ $trigger['threshold'] === 'take_profit' ? 'take profit' : 'stop loss' }}
                </span>
            </td>
            @if($rule->isTrailing())
            <td style="color:var(--muted)">{{ $trigger['peak'] !== null ? $position->currency.' '.number_format($trigger['peak'], 2) : '—' }}</td>
            @endif
        </tr>
        @endforeach
        </tbody>
    </table>
</div>
@elseif($result['snapshots'] > 0)
<div class="card" style="color:var(--muted);text-align:center;padding:40px;">
    These thresholds were never crossed in the window on record.
</div>
@endif
@endsection
