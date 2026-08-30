<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => config('app.appearance') === 'dark'])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }} · {{ config('app.name') }}</title>
    <x-favicon />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
    <x-umani-analytics />
</head>
<body class="min-h-screen bg-zinc-50 text-zinc-800 antialiased dark:bg-zinc-900 dark:text-white">
    <div class="flex min-h-screen items-center justify-center p-6">
        <div class="w-full max-w-80 space-y-6">
            <div class="flex justify-center">
                <x-brand :href="url('/')" />
            </div>

            @if (! empty($title))
                <div class="space-y-2 text-center">
                    <flux:heading size="xl">{{ $title }}</flux:heading>
                    @isset($description)
                        <flux:subheading>{{ $description }}</flux:subheading>
                    @endisset
                </div>
            @endif

            {{ $slot }}
        </div>
    </div>

    <flux:toast />
    @fluxScripts
</body>
</html>
