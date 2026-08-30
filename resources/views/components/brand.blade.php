@props(['href', 'variant' => 'guest'])

<a href="{{ $href }}" {{ $attributes->class('inline-flex font-logo') }} data-brand data-brand-variant="{{ $variant }}" wire:navigate>
    <x-logo alt="" />
    <span data-brand-name @class(['sr-only' => $variant !== 'guest'])>{{ config('app.name') }}</span>
</a>
