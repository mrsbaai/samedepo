<tr>
<td style="background-color: {{ \App\Support\Brand::color('background') }};">
<table class="footer" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="content-cell" align="center" style="color: {{ \App\Support\Brand::color('muted') }};">
{{ Illuminate\Mail\Markdown::parse($slot) }}
</td>
</tr>
</table>
</td>
</tr>
