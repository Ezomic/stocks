<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreRuleRequest;
use App\Http\Requests\UpdateRuleRequest;
use App\Models\Position;
use App\Models\Rule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RuleController extends Controller
{
    public function index(): View
    {
        $rules = Rule::with('position')->get();
        $globalRule = $rules->firstWhere('position_id', null);
        $positionRules = $rules->whereNotNull('position_id')->values();

        $positions = Position::with('rule')->orderBy('symbol')->get();

        return view('rules.index', [
            'globalRule' => $globalRule,
            'positionRules' => $positionRules,
            'positions' => $positions,
            'governedByGlobal' => $positions->filter(
                fn (Position $position): bool => $position->rule === null
            )->values(),
        ]);
    }

    public function create(): View
    {
        $positions = Position::orderBy('symbol')->get();

        return view('rules.create', compact('positions'));
    }

    public function store(StoreRuleRequest $request): RedirectResponse
    {
        Rule::create($this->ruleAttributes($request));

        return redirect('/rules')->with('success', 'Rule saved.');
    }

    public function edit(Rule $rule): View
    {
        $positions = Position::orderBy('symbol')->get();

        return view('rules.edit', compact('rule', 'positions'));
    }

    public function update(UpdateRuleRequest $request, Rule $rule): RedirectResponse
    {
        $rule->update($this->ruleAttributes($request));

        return redirect('/rules')->with('success', 'Rule updated.');
    }

    public function destroy(Rule $rule): RedirectResponse
    {
        $rule->delete();

        return redirect('/rules')->with('success', 'Rule deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function ruleAttributes(Request $request): array
    {
        return [
            'position_id' => $request->integer('position_id') ?: null,
            'take_profit_pct' => $request->filled('take_profit_pct') ? $request->float('take_profit_pct') : null,
            'stop_loss_pct' => $request->filled('stop_loss_pct') ? $request->float('stop_loss_pct') : null,
            'stop_loss_type' => $request->string('stop_loss_type')->toString() ?: 'entry',
            'cooldown_minutes' => $request->integer('cooldown_minutes'),
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
