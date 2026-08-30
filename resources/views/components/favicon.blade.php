@php($faviconVersion = filemtime(public_path('favicon.svg')))

<link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}?v={{ $faviconVersion }}">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}?v={{ $faviconVersion }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('favicon-180x180.png') }}?v={{ $faviconVersion }}">
