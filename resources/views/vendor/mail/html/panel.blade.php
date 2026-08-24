<table class="panel" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-left: 4px solid {{ \App\Support\Brand::color('secondary') }}; margin: 21px 0;">
<tr>
<td class="panel-content" style="background-color: {{ \App\Support\Brand::color('background') }}; color: {{ \App\Support\Brand::color('text') }}; padding: 16px;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="panel-item">
{{ Illuminate\Mail\Markdown::parse($slot) }}
</td>
</tr>
</table>
</td>
</tr>
</table>

