<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="\App\Support\Brand::websiteUrl()">
@if (\App\Support\Brand::logoUrl() === null)
{{ \App\Support\Brand::name() }}
@endif
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ \App\Support\Brand::name() }}. {{ __('All rights reserved.') }}<br>
<a href="{{ \App\Support\Brand::websiteUrl() }}">{{ \App\Support\Brand::websiteUrl() }}</a>
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
