@props(['href'])

<a href="{{ $href }}" {{ $attributes->class('font-logo') }} data-brand wire:navigate>
    <x-logo alt="" />
    <span data-brand-name>{{ config('app.name') }}</span>
</a>
