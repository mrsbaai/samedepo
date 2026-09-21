<div class="mt-10">
    <flux:heading size="lg">{{ $heading }}</flux:heading>

    <div class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-3">
        <flux:radio.group variant="segmented" size="sm" wire:model.live="range">
            <flux:radio value="today" label="Today" />
            <flux:radio value="7d" label="7 days" />
            <flux:radio value="30d" label="30 days" />
            <flux:radio value="custom" label="Custom" />
        </flux:radio.group>

        @if ($this->range === 'custom')
            <flux:date-picker mode="range" size="sm" wire:model.live="customRange" />
        @endif

        <flux:checkbox.group variant="pills" size="sm" wire:model.live="networks" class="sm:ms-auto">
            @foreach ($this->networkOptions as $key => $meta)
                <flux:checkbox :accent="false" value="{{ $key }}" class="data-checked:bg-{{ $meta['chart_color'] }}! dark:data-checked:bg-{{ $meta['chart_color'] }}! data-checked:text-zinc-950! dark:data-checked:text-zinc-950!">
                    <span class="size-1.5 rounded-full bg-{{ $meta['chart_color'] }}"></span>
                    {{ $meta['label'] }}
                </flux:checkbox>
            @endforeach
        </flux:checkbox.group>
    </div>

    @if (! $this->hasIncome)
        <flux:callout icon="chart-bar" class="mt-6">
            <flux:callout.heading>No income in this range</flux:callout.heading>
            <flux:callout.text>Credited deposits appear here once they land in the selected period.</flux:callout.text>
        </flux:callout>
    @else
        <div class="mt-6">
            <flux:subheading>Per network</flux:subheading>
            <flux:chart :value="$this->series['points']" class="mt-2 w-full">
                <flux:chart.viewport class="w-full aspect-[3/1] max-md:aspect-[4/3]">
                    <flux:chart.svg>
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
                </flux:chart.tooltip>
            </flux:chart>

            <div class="mt-3 flex flex-wrap justify-center gap-x-4 gap-y-1">
                @foreach ($this->activeNetworks as $key)
                    <flux:chart.legend label="{{ $this->networkOptions[$key]['label'] }}" class="p-0!">
                        <flux:chart.legend.indicator class="bg-{{ $this->networkOptions[$key]['chart_color'] }}" />
                    </flux:chart.legend>
                @endforeach
            </div>
        </div>

        <div class="mt-8 grid gap-8 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <flux:subheading>Total</flux:subheading>
                <flux:chart :value="$this->series['points']" class="mt-2 w-full">
                    <flux:chart.viewport class="w-full aspect-[3/1] max-md:aspect-[4/3]">
                        <flux:chart.svg>
                            <flux:chart.area field="total" curve="none" class="text-amber-500/20" />
                            <flux:chart.line field="total" curve="none" class="text-amber-500" />

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
                        <flux:chart.tooltip.value field="total" label="Income" prefix="$" />
                    </flux:chart.tooltip>
                </flux:chart>
            </div>

            <div>
                <flux:subheading>Share</flux:subheading>
                <x-charts.donut :segments="$this->donutSegments" :total="$this->totalUsd" class="mt-4" />
            </div>
        </div>
    @endif
</div>
