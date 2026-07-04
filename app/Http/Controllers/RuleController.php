<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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

        return view('rules.index', compact('globalRule', 'positionRules'));
    }

    public function create(): View
    {
        $positions = Position::orderBy('symbol')->get();

        return view('rules.create', compact('positions'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'position_id' => ['nullable', 'exists:positions,id'],
            'take_profit_pct' => ['nullable', 'numeric', 'min:0.01'],
            'stop_loss_pct' => ['nullable', 'numeric', 'min:0.01'],
            'is_active' => ['boolean'],
            'cooldown_minutes' => ['required', 'integer', 'min:1'],
        ]);

        $data['is_active'] = $request->boolean('is_active');

        Rule::create($data);

        return redirect('/rules')->with('success', 'Rule saved.');
    }

    public function edit(Rule $rule): View
    {
        $positions = Position::orderBy('symbol')->get();

        return view('rules.edit', compact('rule', 'positions'));
    }

    public function update(Request $request, Rule $rule): RedirectResponse
    {
        $data = $request->validate([
            'position_id' => ['nullable', 'exists:positions,id'],
            'take_profit_pct' => ['nullable', 'numeric', 'min:0.01'],
            'stop_loss_pct' => ['nullable', 'numeric', 'min:0.01'],
            'is_active' => ['boolean'],
            'cooldown_minutes' => ['required', 'integer', 'min:1'],
        ]);

        $data['is_active'] = $request->boolean('is_active');

        $rule->update($data);

        return redirect('/rules')->with('success', 'Rule updated.');
    }

    public function destroy(Rule $rule): RedirectResponse
    {
        $rule->delete();

        return redirect('/rules')->with('success', 'Rule deleted.');
    }
}
