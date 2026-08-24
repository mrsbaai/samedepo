<div class="mx-auto max-w-7xl space-y-8">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">Fraud Intelligence</flux:heading>
            <flux:text class="mt-1">How risky is each user, device, and IP — and what is connected to what?</flux:text>
        </div>
        <flux:button href="{{ route('admin.security.threats') }}" variant="ghost" icon-trailing="arrow-right" size="sm" wire:navigate>
            Threat Protection
        </flux:button>
    </div>

    @if ($unreviewedAlerts > 0)
        <flux:callout icon="bell-alert" color="orange">
            <flux:callout.heading>{{ $unreviewedAlerts }} unreviewed fraud {{ Str::plural('alert', $unreviewedAlerts) }}</flux:callout.heading>
            <x-slot name="actions">
                <flux:button size="sm" wire:click="markAlertsReviewed">Mark all reviewed</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    {{-- Top summary --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div class="rounded-lg bg-zinc-50 px-6 py-4 dark:bg-zinc-800">
            <flux:subheading>High risk users</flux:subheading>
            <flux:heading size="xl">{{ number_format($highRiskCount) }}</flux:heading>
        </div>
        <div class="rounded-lg bg-zinc-50 px-6 py-4 dark:bg-zinc-800">
            <flux:subheading>Suspicious devices</flux:subheading>
            <flux:heading size="xl">{{ number_format($suspiciousDevices) }}</flux:heading>
        </div>
        <div class="rounded-lg bg-zinc-50 px-6 py-4 dark:bg-zinc-800">
            <flux:subheading>Linked accounts</flux:subheading>
            <flux:heading size="xl">{{ number_format($linkedAccounts) }}</flux:heading>
        </div>
        <div class="rounded-lg bg-zinc-50 px-6 py-4 dark:bg-zinc-800">
            <flux:subheading>In review</flux:subheading>
            <flux:heading size="xl">{{ number_format($reviewCount) }}</flux:heading>
        </div>
    </div>

    <div class="grid gap-8 lg:grid-cols-2">
        {{-- Risk distribution --}}
        <div>
            <flux:heading size="lg" class="mb-3">User risk</flux:heading>
            <div class="space-y-2">
                @foreach (['critical' => 'red', 'high' => 'orange', 'medium' => 'yellow', 'low' => 'zinc'] as $level => $color)
                    <div class="flex items-center justify-between rounded-lg bg-zinc-50 px-4 py-2 dark:bg-zinc-800">
                        <flux:badge size="sm" :color="$color">{{ strtoupper($level) }}</flux:badge>
                        <flux:text variant="strong">{{ number_format(max(0, $distribution[$level])) }}</flux:text>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Suspicious connections --}}
        <div>
            <flux:heading size="lg" class="mb-3">Suspicious connections</flux:heading>
            @if ($connections->isEmpty())
                <flux:text>No linked accounts found.</flux:text>
            @else
                <div class="space-y-2">
                    @foreach ($connections as $connection)
                        <div class="flex items-center justify-between rounded-lg bg-zinc-50 px-4 py-2 dark:bg-zinc-800">
                            <flux:text>
                                <button type="button" class="font-medium underline-offset-2 hover:underline" wire:click="showUser({{ $connection->user_id }})">User #{{ $connection->user_id }}</button>
                                ↔
                                <button type="button" class="font-medium underline-offset-2 hover:underline" wire:click="showUser({{ $connection->linked_user_id }})">User #{{ $connection->linked_user_id }}</button>
                                <span class="text-zinc-500">({{ implode(', ', $connection->reasons ?? []) }})</span>
                            </flux:text>
                            <flux:badge size="sm">{{ $connection->strength }}% link</flux:badge>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- High-risk users --}}
    <div>
        <flux:heading size="lg" class="mb-3">High risk users</flux:heading>
        @if ($highRiskUsers->isEmpty())
            <flux:text>No users with a fraud score yet.</flux:text>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>User</flux:table.column>
                    <flux:table.column>Score</flux:table.column>
                    <flux:table.column class="max-md:hidden">Why</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($highRiskUsers as $risk)
                        <flux:table.row :key="$risk->id">
                            <flux:table.cell variant="strong">#{{ $risk->user_id }} <span class="max-md:hidden font-normal text-zinc-500">{{ $risk->user?->email }}</span></flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" inset="top bottom" :color="match($risk->level) {
                                    'critical' => 'red', 'high' => 'orange', 'medium' => 'yellow', default => 'zinc',
                                }">{{ $risk->score }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="max-md:hidden max-w-64 truncate">{{ collect($risk->breakdown)->pluck('reason')->take(2)->implode('; ') }}</flux:table.cell>
                            <flux:table.cell>{{ strtoupper($risk->user?->fraud_status ?? 'active') }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:button size="sm" variant="ghost" inset="top bottom" wire:click="showUser({{ $risk->user_id }})">Detail</flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    {{-- Scoring metrics --}}
    <div>
        <flux:heading size="lg" class="mb-3">Scoring metrics</flux:heading>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Metric</flux:table.column>
                <flux:table.column>Enabled</flux:table.column>
                <flux:table.column>Weight</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($metrics as $metric)
                    <flux:table.row :key="$metric['key']">
                        <flux:table.cell variant="strong">
                            {{ $metric['label'] }}
                            @unless ($metric['available'])
                                <flux:badge size="sm" color="zinc" inset="top bottom" class="ml-2">No data source yet</flux:badge>
                            @endunless
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:switch :checked="$metric['enabled']" wire:click="toggleMetric('{{ $metric['key'] }}')" />
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:input type="number" size="sm" class="max-w-24" value="{{ $metric['weight'] }}"
                                wire:change="updateMetricWeight('{{ $metric['key'] }}', $event.target.value)" />
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
        <flux:text size="sm" class="mt-2">Metrics without a data source score 0 until a billing module or IP intelligence provider is connected.</flux:text>
    </div>

    {{-- Fraud level policy --}}
    <div>
        <flux:heading size="lg" class="mb-3">Fraud level actions</flux:heading>
        <flux:table>
            <flux:table.columns>
                <flux:table.column></flux:table.column>
                @foreach ($policies as $level => $policy)
                    <flux:table.column>{{ strtoupper($level) }} <span class="font-normal text-zinc-400">{{ config("security.fraud.levels.{$level}.min") }}–{{ config("security.fraud.levels.{$level}.max") }}</span></flux:table.column>
                @endforeach
            </flux:table.columns>
            <flux:table.rows>
                <flux:table.row>
                    <flux:table.cell variant="strong">User status</flux:table.cell>
                    @foreach ($policies as $level => $policy)
                        <flux:table.cell>
                            <flux:select size="sm" wire:change="updatePolicy('{{ $level }}', 'user_status', $event.target.value)">
                                @foreach (['active', 'review', 'blocked'] as $status)
                                    <flux:select.option value="{{ $status }}" :selected="$policy->user_status === $status">{{ ucfirst($status) }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </flux:table.cell>
                    @endforeach
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell variant="strong">Fingerprint</flux:table.cell>
                    @foreach ($policies as $level => $policy)
                        <flux:table.cell>
                            <flux:select size="sm" wire:change="updatePolicy('{{ $level }}', 'block_fingerprint', $event.target.value)">
                                <flux:select.option value="0" :selected="! $policy->block_fingerprint">Allowed</flux:select.option>
                                <flux:select.option value="1" :selected="$policy->block_fingerprint">Blocked</flux:select.option>
                            </flux:select>
                        </flux:table.cell>
                    @endforeach
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell variant="strong">IP</flux:table.cell>
                    @foreach ($policies as $level => $policy)
                        <flux:table.cell>
                            <flux:select size="sm" wire:change="updatePolicy('{{ $level }}', 'block_ip', $event.target.value)">
                                <flux:select.option value="0" :selected="! $policy->block_ip">Allowed</flux:select.option>
                                <flux:select.option value="1" :selected="$policy->block_ip">Blocked</flux:select.option>
                            </flux:select>
                        </flux:table.cell>
                    @endforeach
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell variant="strong">Admin notify</flux:table.cell>
                    @foreach ($policies as $level => $policy)
                        <flux:table.cell>
                            <flux:switch :checked="$policy->notify_admin" wire:click="updatePolicy('{{ $level }}', 'notify_admin', '{{ $policy->notify_admin ? 0 : 1 }}')" />
                        </flux:table.cell>
                    @endforeach
                </flux:table.row>
            </flux:table.rows>
        </flux:table>
    </div>

    {{-- User detail --}}
    <flux:modal wire:model.self="selectedUserId" name="fraud-user-detail" variant="dialog" class="md:w-[32rem]">
        @if ($selectedUser)
            <div class="space-y-4">
                <div class="flex items-start justify-between">
                    <div>
                        <flux:heading size="lg">User #{{ $selectedUser->id }}</flux:heading>
                        <flux:text class="mt-1">{{ $selectedUser->email }}</flux:text>
                    </div>
                    <flux:badge :color="match($selectedUser->risk?->level ?? 'low') {
                        'critical' => 'red', 'high' => 'orange', 'medium' => 'yellow', default => 'zinc',
                    }">{{ $selectedUser->risk?->score ?? 0 }} / 100 · {{ strtoupper($selectedUser->risk?->level ?? 'low') }}</flux:badge>
                </div>

                <div>
                    <flux:subheading>Status</flux:subheading>
                    <flux:text variant="strong" class="mt-1">{{ strtoupper($selectedUser->fraud_status) }}</flux:text>
                </div>

                <div>
                    <flux:subheading>Scoring</flux:subheading>
                    @forelse ($selectedUser->risk?->breakdown ?? [] as $item)
                        <div class="mt-1 flex justify-between gap-4 text-sm">
                            <span>{{ $item['reason'] }}</span>
                            <span class="font-medium">{{ $item['weight'] > 0 ? '+' : '' }}{{ $item['weight'] }}</span>
                        </div>
                    @empty
                        <flux:text class="mt-1">No scoring signals fired.</flux:text>
                    @endforelse
                </div>

                <div class="grid grid-cols-4 gap-2 text-center">
                    @foreach (['Devices' => $selectedUser->devices->count(), 'IPs' => $selectedUser->ips->count(), 'Links' => $selectedUserLinks->count(), 'Threats' => $selectedUserThreats] as $label => $count)
                        <div class="rounded-lg bg-zinc-50 px-2 py-2 dark:bg-zinc-800">
                            <flux:heading size="lg">{{ $count }}</flux:heading>
                            <flux:text size="sm">{{ $label }}</flux:text>
                        </div>
                    @endforeach
                </div>

                @if ($selectedUserLinks->isNotEmpty())
                    <div>
                        <flux:subheading>Related accounts</flux:subheading>
                        @foreach ($selectedUserLinks->take(5) as $link)
                            <div class="mt-1 flex justify-between gap-4 text-sm">
                                <span>#{{ $link->linked_user_id }} {{ $link->linkedUser?->email }}</span>
                                <span class="font-medium">{{ $link->strength }}% linked</span>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="flex flex-wrap gap-2">
                    <flux:select size="sm" class="max-w-40" wire:change="setUserStatus({{ $selectedUser->id }}, $event.target.value)">
                        @foreach (['active', 'review', 'blocked'] as $status)
                            <flux:select.option value="{{ $status }}" :selected="$selectedUser->fraud_status === $status">{{ ucfirst($status) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:button size="sm" wire:click="toggleUserDevices({{ $selectedUser->id }})">
                        {{ $selectedUserDevicesBlocked ? 'Allow' : 'Block' }} fingerprint
                    </flux:button>
                    <flux:button size="sm" wire:click="toggleUserIps({{ $selectedUser->id }})">
                        {{ $selectedUserIpsBlocked ? 'Allow' : 'Block' }} IP
                    </flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="markFalsePositive({{ $selectedUser->id }})">Mark false positive</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
