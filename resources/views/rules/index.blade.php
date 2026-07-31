@extends('layout')
@section('title', 'Rules')
@section('content')
<div class="page-header">
    <h1>Rules</h1>
    <a href="/rules/create" class="btn btn-primary">+ Add rule</a>
</div>

@if($globalRule)
<h2>Global default</h2>
<div class="card">
    <div style="display:flex;gap:24px;align-items:center">
        <div><span style="color:var(--muted);font-size:12px">Take profit</span><br><strong>{{ $globalRule->take_profit_pct ?? '—' }}%</strong></div>
        <div><span style="color:var(--muted);font-size:12px">Stop loss</span><br><strong>{{ $globalRule->stop_loss_pct ?? '—' }}%</strong></div>
        <div><span style="color:var(--muted);font-size:12px">Cooldown</span><br><strong>{{ $globalRule->cooldown_minutes }}min</strong></div>
        <div><span class="badge {{ $globalRule->is_active ? 'badge-green' : '' }}">{{ $globalRule->is_active ? 'Active' : 'Paused' }}</span></div>
        <div style="margin-left:auto;display:flex;gap:8px">
            <a href="/rules/{{ $globalRule->id }}/edit" class="btn btn-sm">Edit</a>
            <form method="POST" action="/rules/{{ $globalRule->id }}" style="margin:0">
                @csrf @method('DELETE')
                <button class="btn btn-sm btn-danger" onclick="return confirm('Delete this rule?')">Delete</button>
            </form>
        </div>
    </div>
</div>
@endif

<h2>Position rules</h2>
@if($positionRules->isEmpty())
    <div class="card" style="color:var(--muted);font-size:13px">No position-level rules yet.</div>
@else
<div class="card" style="padding:0">
    <table>
        <thead><tr><th>Position</th><th>Take profit</th><th>Stop loss</th><th>Cooldown</th><th>Status</th><th>Last triggered</th><th></th></tr></thead>
        <tbody>
        @foreach($positionRules as $r)
        <tr>
            <td><a href="/positions/{{ $r->position_id }}"><strong>{{ $r->position->symbol }}</strong></a></td>
            <td>{{ $r->take_profit_pct !== null ? $r->take_profit_pct.'%' : '—' }}</td>
            <td>{{ $r->stop_loss_pct !== null ? $r->stop_loss_pct.'%' : '—' }}</td>
            <td>{{ $r->cooldown_minutes }}min</td>
            <td><span class="badge {{ $r->is_active ? 'badge-green' : '' }}">{{ $r->is_active ? 'Active' : 'Paused' }}</span></td>
            <td style="color:var(--muted)">{{ $r->last_triggered_at?->diffForHumans() ?? '—' }}</td>
            <td style="display:flex;gap:6px">
                <a href="/rules/{{ $r->id }}/edit" class="btn btn-sm">Edit</a>
                <form method="POST" action="/rules/{{ $r->id }}" style="margin:0">
                    @csrf @method('DELETE')
                    <button class="btn btn-sm btn-danger" onclick="return confirm('Delete?')">Delete</button>
                </form>
            </td>
        </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif
@endsection
