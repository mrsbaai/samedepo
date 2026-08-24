<x-mail::message>
# {{ $messageBody }}

<x-mail::button :url="'http://example.com'" color="primary">
Primary Button
</x-mail::button>

<x-mail::button :url="'http://example.com'" color="success">
Success Button
</x-mail::button>

<x-mail::panel>
A test panel.
</x-mail::panel>

Thanks,
{{ config('app.name') }}

<x-slot:subcopy>
This is a subcopy section.
</x-slot:subcopy>
</x-mail::message>
