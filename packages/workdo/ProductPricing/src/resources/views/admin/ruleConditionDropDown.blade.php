{!! Form::select('rule_condition', $ruleConditions, $rule->rule_status ?? 'none', [
    'class' => 'form-control rule_condition',
    'data-id' => $rule->id,
]) !!}