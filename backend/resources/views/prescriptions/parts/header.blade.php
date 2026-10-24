{{-- MEMBRETE: una quinta parte de la hoja (o el alto elegido en Ajustes). --}}
@if ($headerMode === 'none')
    {{-- La hoja diseñada ya lo trae impreso. --}}
@elseif ($headerMode === 'image')
    <table class="head-img">
        <tr><td>
            @if ($bannerSize)
                <img src="{{ $headerImage }}" alt="membrete" style="width: {{ $bannerSize['w'] }}mm; height: {{ $bannerSize['h'] }}mm;">
            @else
                <img src="{{ $headerImage }}" alt="membrete" style="max-width: 100%; max-height: {{ $headerBox }}mm;">
            @endif
        </td></tr>
    </table>
@else
    <table class="head">
        <tr>
            @if ($logo)
                <td class="logo" style="height: {{ $headerBox }}mm; width: {{ ($logoSize['w'] ?? 30) + 2 }}mm;">
                    @if ($logoSize)
                        <img src="{{ $logo }}" alt="logo" style="width: {{ $logoSize['w'] }}mm; height: {{ $logoSize['h'] }}mm;">
                    @else
                        <img src="{{ $logo }}" alt="logo" style="max-width: 30mm; max-height: {{ $headerBox - 4 }}mm;">
                    @endif
                </td>
            @endif
            <td class="head-text" style="height: {{ $headerBox }}mm;">
                <div class="head-name">{{ $settings['header'] ?? ($workspace?->name ?? 'Consultorio Médico') }}</div>
                @if (!empty($settings['subheader']))<div class="head-sub">{{ $settings['subheader'] }}</div>@endif
                <div class="head-contact">
                    @if (!empty($settings['address']))<b>Dirección:</b> {{ $settings['address'] }}<br>@endif
                    @if (!empty($settings['phone']))<b>Teléf.:</b> {{ $settings['phone'] }}@endif
                    @if (!empty($settings['email']))@if (!empty($settings['phone']))@if ($portrait)<br>@else &nbsp; @endif @endif<b>Correo:</b> {{ $settings['email'] }}@endif
                    @if (!empty($acessCode))<br><b>Cód. ACESS:</b> {{ $acessCode }}@endif
                </div>
            </td>
        </tr>
    </table>
@endif
<div class="code">CÓDIGO: <b>{{ str_pad((string) $record->id, 6, '0', STR_PAD_LEFT) }}</b></div>

{{-- DATOS --}}
<div class="meta">
    <div><b>FECHA:</b> {{ $fechaLugar }} &nbsp;|&nbsp; <b>VÁLIDA HASTA:</b> {{ $validUntil?->format('d-m-Y') }}</div>
    <div><b>PACIENTE:</b> {{ \Illuminate\Support\Str::upper($patient?->name ?? '—') }}</div>
    <div><b>CÉDULA:</b> {{ $patient?->id_number ?? '—' }} &nbsp;|&nbsp; <b>SEXO:</b> {{ $sexLabel }}</div>
    <div><b>EDAD:</b> {{ $ageDetailed ? \Illuminate\Support\Str::upper($ageDetailed) : '—' }}</div>
    @if ($diagnoses->isNotEmpty())
        <div><b>DIAGNÓSTICO:</b>
            @foreach ($diagnoses as $dx)<span>{{ $dx->cie10_code }} {{ \Illuminate\Support\Str::upper($dx->cie10_description) }}@if($dx->type==='secondary') (SEC.)@elseif($dx->type==='presumptive') (PRES.)@endif</span>@if(!$loop->last); @endif @endforeach
        </div>
    @elseif (!empty($record->diagnosis))
        <div><b>DIAGNÓSTICO:</b> {{ \Illuminate\Support\Str::upper($record->diagnosis) }}</div>
    @endif
</div>
