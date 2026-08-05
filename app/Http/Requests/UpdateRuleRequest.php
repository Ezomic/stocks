<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Rule;

class UpdateRuleRequest extends StoreRuleRequest
{
    protected function ignoredRuleId(): ?int
    {
        $rule = $this->route('rule');

        return $rule instanceof Rule ? $rule->id : null;
    }
}
