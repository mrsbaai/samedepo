<div class="space-y-5">
    <flux:heading size="lg">Security history</flux:heading>

    @if ($events->isEmpty())
        <flux:callout variant="secondary" icon="information-circle">
            <flux:callout.text>No security activity has been recorded yet.</flux:callout.text>
        </flux:callout>
    @else
        <flux:table :rows="$events">
            <flux:table.columns>
                <flux:table.column>Activity</flux:table.column>
                <flux:table.column>IP address</flux:table.column>
                <flux:table.column>Device</flux:table.column>
                <flux:table.column>When</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($events as $event)
                    <flux:table.row :key="$event->id">
                        <flux:table.cell>{{ $event->event_type }}</flux:table.cell>
                        <flux:table.cell>{{ $event->ip_address ?: 'Unavailable' }}</flux:table.cell>
                        <flux:table.cell>{{ $event->device ?: $event->user_agent ?: 'Unavailable' }}</flux:table.cell>
                        <flux:table.cell>{{ $event->occurred_at->toDayDateTimeString() }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        {{ $events->links() }}
    @endif
</div>
