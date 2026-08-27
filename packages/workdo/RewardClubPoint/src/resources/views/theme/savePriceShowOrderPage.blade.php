<li class="flex justify-between">
    <span class="text-gray-800">{{ __('Reward Price') }}:</span>
    <span class="text-gray-800">
        @if (isset($rewardHistoryDetail['reward_price']))
           - {{ currency_format_with_sym($rewardHistoryDetail['reward_price'] ?? 0, $rewardHistoryDetail['store_id']) ?? SetNumberFormat($rewardHistoryDetail['reward_price'] ?? 0) }}
        @else
            {{ SetNumberFormat(0) }}
        @endif
    </span>
</li>
