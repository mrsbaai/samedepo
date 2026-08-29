@props(['href'])

<flux:brand :href="$href" :name="config('app.name')" {{ $attributes->class('font-logo') }} wire:navigate>
    <x-slot name="logo">
        <x-logo />
    </x-slot>
</flux:brand>
