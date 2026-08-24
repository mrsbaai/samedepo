<table class="subcopy" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-top: 1px solid {{ \App\Support\Brand::color('background') }}; margin-top: 25px; padding-top: 25px;">
<tr>
<td style="color: {{ \App\Support\Brand::color('muted') }};">
{{ Illuminate\Mail\Markdown::parse($slot) }}
</td>
</tr>
</table>
