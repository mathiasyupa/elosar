{{-- Indicaciones por medicamento. --}}
<div class="sec">
    <div class="sec-title">Indicaciones.-</div>
    @forelse ($items as $it)
        <div class="ind-item">
            <div class="ind-head">{{ $loop->iteration }}. {{ $it['name'] }}@if ($it['qty']) &nbsp; # {{ $it['qty'] }} ({{ $it['qtyWords'] }})@endif</div>
            @if ($it['brand'])<div class="ind-brand">({{ $it['brand'] }})</div>@endif
            <div class="ind-body">{{ $it['ind'] }}@if ($it['ind'] && $it['extra']) — {{ $it['extra'] }}@endif</div>
        </div>
    @empty
        <div class="ind-body">{{ $record->treatment ?: '—' }}</div>
    @endforelse
</div>
