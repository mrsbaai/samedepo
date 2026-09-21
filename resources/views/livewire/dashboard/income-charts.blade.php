<div>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="lg">{{ $heading }}</flux:heading>
            @if ($this->hasIncome)
                <div class="mt-1 flex items-baseline gap-2">
                    <flux:heading size="xl" class="font-ledger">{{ $this->totalUsd }}</flux:heading>
                    <flux:text size="sm" variant="subtle">in the selected range</flux:text>
                </div>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <flux:radio.group variant="segmented" size="sm" wire:model.live="range">
                <flux:radio value="today" label="Today" />
                <flux:radio value="7d" label="7 days" />
                <flux:radio value="30d" label="30 days" />
                <flux:radio value="custom" label="Custom" />
            </flux:radio.group>

            @if ($this->range === 'custom')
                <flux:date-picker mode="range" size="sm" wire:model.live="customRange" />
            @endif
        </div>
    </div>

    <flux:checkbox.group variant="pills" size="sm" wire:model.live="networks" class="mt-4">
        @foreach ($this->networkOptions as $key => $meta)
            <flux:checkbox :accent="false" value="{{ $key }}">
                <span class="size-2 rounded-full bg-{{ $meta['chart_color'] }} opacity-40 in-data-checked:opacity-100"></span>
                {{ $meta['label'] }}
            </flux:checkbox>
        @endforeach
    </flux:checkbox.group>

    @if (! $this->hasIncome)
        <flux:callout icon="chart-bar" class="mt-6">
            <flux:callout.heading>No income in this range</flux:callout.heading>
            <flux:callout.text>Credited deposits appear here once they land in the selected period.</flux:callout.text>
        </flux:callout>
    @else
        <div class="mt-6 grid gap-8 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <flux:chart :value="$this->series['points']" class="w-full">
                    <flux:chart.viewport class="w-full aspect-[3/1] max-md:aspect-[4/3]">
                        <flux:chart.svg>
                            <flux:chart.area field="total" curve="none" class="text-zinc-400/10" />
                            <flux:chart.line field="total" curve="none" class="text-zinc-500/40" />
                            @foreach ($this->activeNetworks as $key)
                                <flux:chart.line field="{{ $key }}" curve="none" class="text-{{ $this->networkOptions[$key]['chart_color'] }}" />
                            @endforeach

                            <flux:chart.axis axis="x" field="t" interval="{{ $this->series['bucket'] }}" :format="$this->xAxisFormat">
                                <flux:chart.axis.tick />
                                <flux:chart.axis.line />
                            </flux:chart.axis>

                            <flux:chart.axis axis="y" tick-prefix="$" :format="['notation' => 'compact', 'compactDisplay' => 'short']">
                                <flux:chart.axis.grid />
                                <flux:chart.axis.tick />
                            </flux:chart.axis>

                            <flux:chart.cursor />
                        </flux:chart.svg>
                    </flux:chart.viewport>

                    <flux:chart.tooltip>
                        <flux:chart.tooltip.heading field="t" />
                        @foreach ($this->activeNetworks as $key)
                            <flux:chart.tooltip.value field="{{ $key }}" label="{{ $this->networkOptions[$key]['label'] }}" prefix="$" />
                        @endforeach
                        <flux:chart.tooltip.value field="total" label="Total" prefix="$" />
                    </flux:chart.tooltip>
                </flux:chart>

                <div class="mt-3 flex flex-wrap justify-center gap-x-4 gap-y-1">
                    @foreach ($this->activeNetworks as $key)
                        <flux:chart.legend label="{{ $this->networkOptions[$key]['label'] }}" class="p-0!">
                            <flux:chart.legend.indicator class="bg-{{ $this->networkOptions[$key]['chart_color'] }}" />
                        </flux:chart.legend>
                    @endforeach
                    <flux:chart.legend label="Total" class="p-0!">
                        <flux:chart.legend.indicator class="bg-zinc-500" />
                    </flux:chart.legend>
                </div>
            </div>

            <x-charts.donut :segments="$this->donutSegments" :total="$this->totalUsd" class="self-center" />
        </div>
    @endif
</div>
