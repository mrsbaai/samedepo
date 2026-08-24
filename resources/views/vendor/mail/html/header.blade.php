@props(['url'])
@php($brandUrl = \App\Support\Brand::websiteUrl())
@php($logoUrl = \App\Support\Brand::logoUrl())
<tr>
<td class="header" style="text-align: center; padding: 25px 0;">
<a href="{{ $url ?: $brandUrl }}" style="display: inline-block; color: {{ \App\Support\Brand::color('text') }}; font-size: 19px; font-weight: bold; text-decoration: none;">
@if ($logoUrl)
<img src="{{ $logoUrl }}" alt="{{ \App\Support\Brand::logoAlt() }}" class="logo" style="width: {{ \App\Support\Brand::logoWidth() }}px; height: auto; max-width: 100%;" width="{{ \App\Support\Brand::logoWidth() }}">
@else
{{ \App\Support\Brand::name() }}
@endif
</a>
</td>
</tr>
