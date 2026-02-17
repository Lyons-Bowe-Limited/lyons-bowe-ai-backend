@props(['url'])
<tr>
<td class="header" style="background-color: #353535;">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
@if (trim($slot) === 'Laravel' || trim($slot) === config('app.name') || trim($slot) === 'Lyons Bowe')
<img src="https://cdn.prod.website-files.com/674ed6e8a40784afab6e858a/67d32a350becfce0b9a4cb05_logo%20(3).avif" class="logo" alt="Lyons Bowe Logo" style="height: 60px; max-height: 60px; width: auto;">
@else
{!! $slot !!}
@endif
</a>
</td>
</tr>
