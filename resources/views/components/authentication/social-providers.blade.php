@props(['providers' => null])

@php
$providers ??= config('authentication.social');
$enabled = array_filter($providers, fn ($settings) => is_array($settings) && ($settings['enabled'] ?? false));
@endphp

@if ($enabled)
    <div class="space-y-4">
        @foreach ($enabled as $provider => $settings)
            <flux:button href="{{ route('social.redirect', $provider) }}" variant="outline" class="w-full" wire:navigate>
                @switch(strtolower($provider))
                    @case('github')
                        <x-lucide-github class="h-5 w-5" />
                        @break
                    @case('apple')
                        <x-lucide-apple class="h-5 w-5" />
                        @break
                    @case('facebook')
                        <x-lucide-facebook class="h-5 w-5" />
                        @break
                    @case('twitter')
                    @case('x')
                        <x-lucide-twitter class="h-5 w-5" />
                        @break
                @endswitch
                Continue with {{ ucfirst($provider) }}
            </flux:button>
        @endforeach

        <flux:separator text="or" />
    </div>
@endif
