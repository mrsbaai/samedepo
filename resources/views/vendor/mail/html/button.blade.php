@props([
    'url',
    'color' => 'primary',
    'align' => 'center',
])
@php($buttonColor = match ($color) {
    'success' => \App\Support\Brand::color('success'),
    'error' => \App\Support\Brand::color('error'),
    'secondary' => \App\Support\Brand::color('secondary'),
    default => \App\Support\Brand::color('primary'),
})
@php($buttonTextColor = match ($color) {
    'primary' => '#171717',
    default => '#ffffff',
})
<table class="action" align="{{ $align }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td>
<a href="{{ $url }}" class="button button-{{ $color }}" target="_blank" rel="noopener" style="background-color: {{ $buttonColor }}; border-bottom: 8px solid {{ $buttonColor }}; border-left: 18px solid {{ $buttonColor }}; border-right: 18px solid {{ $buttonColor }}; border-top: 8px solid {{ $buttonColor }}; border-radius: 4px; color: {{ $buttonTextColor }}; display: inline-block; font-weight: 600; overflow: hidden; text-decoration: none;">{!! $slot !!}</a>
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
</table>
