@props(['class' => 'h-5 w-auto'])

<img src="{{ asset('logo.svg') }}" alt="{{ config('app.name') }} logo" {{ $attributes->merge(['class' => $class]) }}>
