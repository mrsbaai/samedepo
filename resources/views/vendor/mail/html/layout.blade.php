<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<title>{{ \App\Support\Brand::name() }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<style>
body,
body *:not(html):not(style):not(br):not(tr):not(code) {
    font-family: {!! \App\Support\Brand::font('body') !!};
}

body {
    color: {{ \App\Support\Brand::color('text') }};
}

h1, h2, h3 {
    color: {{ \App\Support\Brand::color('text') }};
    font-family: {!! \App\Support\Brand::font('heading') !!};
}

a {
    color: {{ \App\Support\Brand::color('primary') }};
}

p.sub {
    color: {{ \App\Support\Brand::color('muted') }};
}

@media only screen and (max-width: 600px) {
    .inner-body {
        width: 100% !important;
    }

    .footer {
        width: 100% !important;
    }
}

@media only screen and (max-width: 500px) {
    .button {
        width: 100% !important;
    }
}
</style>
{!! $head ?? '' !!}
</head>
<body style="background-color: {{ \App\Support\Brand::color('background') }};">

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background-color: {{ \App\Support\Brand::color('background') }};">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}

<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;">
<table class="inner-body" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation" style="background-color: {{ \App\Support\Brand::color('surface') }}; border-color: {{ \App\Support\Brand::color('background') }};">
<!-- Body content -->
<tr>
<td class="content-cell" style="color: {{ \App\Support\Brand::color('text') }};">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
