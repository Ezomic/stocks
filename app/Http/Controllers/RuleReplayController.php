<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ReplayRuleAction;
use App\Http\Requests\ReplayRuleRequest;
use App\Models\Position;
use App\Models\PriceSnapshot;
use App\Models\Rule;
use Illuminate\View\View;

class RuleReplayController extends Controller
{
    public function __invoke(ReplayRuleRequest $request, ReplayRuleAction $replay): View
    {
        $position = Position::query()
            ->where('id', $request->integer('position_id'))
            ->firstOrFail();

        // Deliberately not saved: this is a rule being considered, not one that exists.
        $rule = new Rule([
            'take_profit_pct' => $request->filled('take_profit_pct') ? $request->float('take_profit_pct') : null,
            'stop_loss_pct' => $request->filled('stop_loss_pct') ? $request->float('stop_loss_pct') : null,
            'stop_loss_type' => $request->string('stop_loss_type')->toString() ?: 'entry',
            'cooldown_minutes' => $request->integer('cooldown_minutes'),
        ]);

        return view('rules.replay', [
            'position' => $position,
            'rule' => $rule,
            'result' => $replay->handle($position, $rule),
            'retentionDays' => PriceSnapshot::retentionDays(),
        ]);
    }
}
