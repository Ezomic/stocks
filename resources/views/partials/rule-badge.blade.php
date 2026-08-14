@php
    $own = $position->rule;
    $governing = $position->activeRule($globalRule ?? null);
@endphp

@if($position->isShort())
    <span class="badge badge-orange" title="Short positions are shown but never traded by the engine. See web/CLAUDE.md.">Short, not traded</span>
@elseif($own && ! $own->is_active)
    <span class="badge badge-orange" title="This position has its own rule and it is paused, so it is not being traded">Paused</span>
@elseif($governing === null)
    <span class="badge">No rule</span>
@else
    <span class="badge {{ $governing->alertsOnly() ? 'badge-orange' : 'badge-blue' }}"
          title="{{ $governing->alertsOnly() ? 'Notifies only, places no orders' : 'Places a sell order when a level is crossed' }}">
        @unless($own) Global: @endunless
        @if($governing->alertsOnly()) Alert: @endif
        TP {{ $governing->take_profit_pct ?? '—' }}% / SL {{ $governing->stop_loss_pct ?? '—' }}%
    </span>
@endif
