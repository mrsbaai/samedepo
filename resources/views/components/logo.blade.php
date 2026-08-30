@props(['class' => 'h-5 w-auto', 'alt' => null])

<img src="{{ asset('logo.svg') }}" alt="{{ $alt ?? config('app.name').' logo' }}" {{ $attributes->merge(['class' => $class]) }}>
