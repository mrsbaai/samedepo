@props(['websiteId' => config('services.umani.website_id')])

@if ($websiteId)
    <script defer src="https://lilstat.com/lilstat.js" data-website-id="{{ $websiteId }}"></script>
@endif
