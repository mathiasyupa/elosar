{{-- 'none' = la hoja diseñada ya trae el membrete impreso. --}}
@if (($headerMode ?? 'digital') === 'image' && !empty($headerImage))
    <div class="head-img"><img src="{{ $headerImage }}" alt="membrete"{!! !empty($headerImageStyle) ? ' style="'.e($headerImageStyle).'"' : '' !!}></div>
@elseif (($headerMode ?? 'digital') !== 'none')
    <table class="header">
        <tr>
            @if ($logo)
                <td class="logo"><img src="{{ $logo }}" alt="logo"></td>
            @endif
            <td class="head-text">
                <div class="head-name">
                    {{ $settings['header'] ?? ($workspace?->name ?? 'Consultorio Médico') }}
                </div>
                @if (!empty($settings['subheader']))
                    <div class="head-sub">{{ $settings['subheader'] }}</div>
                @endif
                <div class="head-contact">
                    @if (!empty($settings['address'])) {{ $settings['address'] }} @endif
                    @if (!empty($settings['phone'])) &nbsp;·&nbsp; Tel: {{ $settings['phone'] }} @endif
                    @if (!empty($settings['email'])) &nbsp;·&nbsp; {{ $settings['email'] }} @endif
                </div>
            </td>
        </tr>
    </table>
@endif

<table class="meta">
    <tr>
        <td class="label">Paciente:</td>
        <td>{{ $patient?->name ?? '—' }}</td>
        <td class="label">Fecha:</td>
        <td>{{ $date?->format('d/m/Y') ?? '' }}</td>
    </tr>
    <tr>
        <td class="label">Cédula:</td>
        <td>{{ $patient?->id_number ?? '—' }}</td>
        <td class="label">Ciudad:</td>
        <td>{{ $patient?->city ?? '—' }}</td>
    </tr>
</table>
