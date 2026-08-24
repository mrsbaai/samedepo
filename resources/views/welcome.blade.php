<!DOCTYPE html>
<html lang="en" @class(['dark' => config('app.appearance') === 'dark'])>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-screen bg-zinc-50 p-6 text-zinc-800 antialiased dark:bg-zinc-950 dark:text-white">
    <main class="flex min-h-screen items-center justify-center">
        <flux:card class="w-full max-w-md text-center">
            <div class="mb-6 flex justify-center">
                <x-lucide-box class="h-12 w-12 text-zinc-900 dark:text-white" />
            </div>

            <flux:heading size="xl" level="1">{{ config('app.name') }}</flux:heading>
            <flux:text class="mb-8 mt-2 text-zinc-500 dark:text-zinc-400">Project OS — build one verified step at a time.</flux:text>

            <div class="flex justify-center gap-3">
                <flux:button href="{{ route('signin') }}" variant="primary">Sign in</flux:button>
                <flux:button href="{{ route('signup') }}" variant="outline">Sign up</flux:button>
            </div>
        </flux:card>
    </main>

    @fluxScripts
</body>
</html>
