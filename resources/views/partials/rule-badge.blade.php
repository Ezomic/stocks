@php
    $own = $position->rule;
    $governing = $position->activeRule($globalRule ?? null);
@endphp

@if($own && ! $own->is_active)
    <span class="badge badge-orange" title="This position has its own rule and it is paused, so it is not being traded">Paused</span>
@elseif($governing === null)
    <span class="badge">No rule</span>
@else
    <span class="badge badge-blue">
        @unless($own) Global: @endunless
        TP {{ $governing->take_profit_pct ?? '—' }}% / SL {{ $governing->stop_loss_pct ?? '—' }}%
    </span>
@endif
