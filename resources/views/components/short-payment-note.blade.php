@props(['row'])

<details class="mt-1 max-w-xs text-left">
    <summary class="cursor-pointer text-xs font-medium text-amber-600 dark:text-amber-400 hover:underline">
        {{ $row['status'] === 'below_minimum' ? 'What to tell your customer' : 'What happened' }}
    </summary>
    <div class="mt-1 space-y-1 text-xs leading-relaxed text-zinc-600 dark:text-zinc-300">
        @if ($row['status'] === 'below_minimum')
            <p>Your customer sent <strong>{{ $row['gross'] }} {{ $row['symbol'] }}</strong> on {{ $row['networkLabel'] }}. The minimum is <strong>{{ $row['short']['minimum'] }} {{ $row['symbol'] }}</strong>, so this hasn't been credited yet.</p>
            <p>Tell them: <em>&ldquo;Send at least <strong>{{ $row['short']['amount_needed'] }} {{ $row['symbol'] }}</strong> more to the same address on the same network before <strong>{{ $row['short']['expires_at']?->format('j M Y, H:i') }} UTC</strong>. Both payments will then be credited together.&rdquo;</em></p>
            <p>If nothing more arrives by then, the {{ $row['short']['received_total'] }} {{ $row['symbol'] }} expires and can't be credited.</p>
        @else
            <p>Below the {{ $row['forfeitedMinimum'] }} {{ $row['symbol'] }} minimum and not topped up within {{ (int) config('blockchain.short_payment_expiry_days', 7) }} days, so it expired and wasn't credited. Contact support if your customer believes this is wrong.</p>
        @endif
    </div>
</details>
