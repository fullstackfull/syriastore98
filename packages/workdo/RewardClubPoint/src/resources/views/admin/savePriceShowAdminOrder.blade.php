<li class="d-flex justify-content-end">
    <span>{{ __('Reward Price') }} :</span>
    <span class="text-start ps-3"><b>{{ currency_format_with_sym(($rewardHistoryDetail['reward_price'] ?? 0), getCurrentStore()) ?? SetNumberFormat($rewardHistoryDetail['reward_price']) }}</b></span>
</li>