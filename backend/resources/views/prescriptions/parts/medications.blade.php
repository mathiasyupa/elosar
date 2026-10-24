{{-- Medicamentos y cantidad. --}}
<div class="sec">
    <table class="rx">
        <thead>
            <tr><th style="width:14px;"></th><th>Medicamentos.-</th><th class="qty">Cantidad.-</th></tr>
        </thead>
        <tbody>
            @forelse ($items as $it)
                <tr>
                    <td class="num">{{ $loop->iteration }}.</td>
                    <td class="med">
                        <b>{{ $it['name'] }}</b>
                        @if ($it['brand'])<br><span class="brand">({{ $it['brand'] }})</span>@endif
                        @if ($it['ind'])<br><span class="note">{{ $it['ind'] }}</span>@endif
                        @if ($it['ctrl'])<br><span class="ctrl">★ CONTROLADO</span>@endif
                    </td>
                    {{-- Prescriptions written before quantity
                         existed (and any left null) print a dash:
                         an empty cell reads as a printing defect,
                         and inventing a dispensing amount is not an
                         option on a signed prescription. --}}
                    <td class="qty">
                        @if ($it['qty'])<b># {{ $it['qty'] }}</b><br><span>({{ $it['qtyWords'] }})</span>@else<b>—</b>@endif
                    </td>
                </tr>
            @empty
                <tr><td></td><td colspan="2" class="note">{{ $record->treatment ?: '—' }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
