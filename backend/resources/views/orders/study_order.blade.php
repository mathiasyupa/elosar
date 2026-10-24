<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Solicitud de exámenes</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        @page { margin: 0; }
        body { margin: 0; color: #1a1a1a; font-size: 11px; }
        .page { padding: 28px 38px 70px; }

        .header { width: 100%; border-bottom: 2px solid #1F4E79; padding-bottom: 10px; }
        .header td { vertical-align: middle; }
        .logo { width: 80px; }
        .logo img { max-width: 80px; max-height: 80px; }
        .head-text { padding-left: 14px; }
        .head-name { font-size: 17px; font-weight: bold; color: #1F4E79; }
        .head-sub { font-size: 10px; color: #444; margin-top: 2px; }
        .head-contact { font-size: 9px; color: #666; margin-top: 3px; }

        .doc-title { margin-top: 14px; text-align: center; font-size: 15px; font-weight: bold;
                     color: #1F4E79; text-transform: uppercase; letter-spacing: .5px; }

        .meta { width: 100%; margin-top: 12px; font-size: 11px; border: 1px solid #d9d9d9; border-radius: 4px; }
        .meta td { padding: 4px 8px; }
        .meta .label { color: #1F4E79; font-weight: bold; }

        .section-label { margin-top: 14px; font-size: 10px; font-weight: bold; color: #1F4E79;
                         text-transform: uppercase; letter-spacing: .5px; }
        .box { margin-top: 5px; padding: 8px 10px; border: 1px solid #d9d9d9; border-radius: 4px;
               background: #fafafa; white-space: pre-line; line-height: 1.45; }

        .group { margin-top: 12px; }
        .group-title { font-size: 11px; font-weight: bold; color: #1F4E79; border-bottom: 1px solid #cdd9e6;
                       padding-bottom: 3px; margin-bottom: 5px; }
        .item { font-size: 11px; padding: 2px 0; }
        .chk { display: inline-block; width: 11px; height: 11px; border: 1px solid #1F4E79;
               margin-right: 6px; vertical-align: middle; }
        .item-note { color: #666; font-size: 9.5px; }

        .signature { margin-top: 46px; width: 100%; }
        .signature td { text-align: center; vertical-align: bottom; }
        .sign-img { height: 55px; }
        .sign-line { border-top: 1px solid #333; width: 250px; margin: 0 auto; padding-top: 4px; }
        .sign-name { font-weight: bold; }
        .sign-meta { font-size: 9.5px; color: #555; }
        .physical-signature-space { height: 92px; padding-top: 72px; }

        .footer { position: fixed; bottom: 0; left: 0; right: 0; padding: 7px 38px;
                  border-top: 1px solid #e0e0e0; font-size: 8.5px; color: #888; text-align: center; }

        .head-img { text-align: center; padding-bottom: 6px; }
        .head-img img { max-width: 100%; max-height: 90px; }
        @include('partials.pdf-sheet-styles')
    </style>
</head>
@php
    $sexLabel = match ($patient?->gender) { 'male' => 'Masculino', 'female' => 'Femenino', default => '—' };
    $docTitle = $order->type === \App\Models\StudyOrder::TYPE_IMAGING
        ? 'Solicitud de Imagenología'
        : 'Solicitud de Exámenes de Laboratorio';
@endphp
<body>
@include('partials.pdf-sheet')
    <div class="page">

        {{-- ============ MEMBRETE ============ --}}
        @if ($headerMode === 'none')
            {{-- La hoja diseñada ya lo trae impreso. --}}
        @elseif ($headerMode === 'image' && $headerImage)
            <div class="head-img"><img src="{{ $headerImage }}" alt="membrete"{!! !empty($headerImageStyle) ? ' style="'.e($headerImageStyle).'"' : '' !!}></div>
        @else
        <table class="header">
            <tr>
                @if ($logo)
                    <td class="logo"><img src="{{ $logo }}" alt="logo"></td>
                @endif
                <td class="head-text">
                    <div class="head-name">{{ $settings['header'] ?? ($workspace?->name ?? 'Consultorio Médico') }}</div>
                    @if (!empty($settings['subheader']))
                        <div class="head-sub">{{ $settings['subheader'] }}</div>
                    @endif
                    <div class="head-contact">
                        @if (!empty($settings['address'])) {{ $settings['address'] }} @endif
                        @if (!empty($settings['phone'])) &nbsp;·&nbsp; Tel: {{ $settings['phone'] }} @endif
                        @if (!empty($settings['email'])) &nbsp;·&nbsp; {{ $settings['email'] }} @endif
                        @if (!empty($acessCode)) &nbsp;·&nbsp; Cód. Establecimiento (ACESS): {{ $acessCode }} @endif
                    </div>
                </td>
            </tr>
        </table>
        @endif

        <div class="doc-title">{{ $docTitle }}</div>

        {{-- ============ DATOS ============ --}}
        <table class="meta">
            <tr>
                <td class="label" style="width:80px;">N° Orden:</td>
                <td>{{ str_pad((string) $order->id, 6, '0', STR_PAD_LEFT) }}</td>
                <td class="label" style="width:70px;">Fecha:</td>
                <td>{{ $date?->format('d/m/Y') }}</td>
                <td class="label" style="width:75px;">Prioridad:</td>
                <td>{{ $order->priorityLabel() }}</td>
            </tr>
            <tr>
                <td class="label">Paciente:</td>
                <td>{{ $patient?->name ?? '—' }}</td>
                <td class="label">Cédula:</td>
                <td>{{ $patient?->id_number ?? '—' }}</td>
                <td class="label">Sexo:</td>
                <td>{{ $sexLabel }}</td>
            </tr>
            <tr>
                <td class="label">Edad:</td>
                <td colspan="3">{{ $ageDetailed ?? '—' }}</td>
                <td class="label">A realizarse:</td>
                <td>{{ $order->to_be_performed_at?->format('d/m/Y') ?? '—' }}</td>
            </tr>
        </table>

        {{-- ============ INDICACIÓN CLÍNICA / DIAGNÓSTICO ============ --}}
        @php
            $clinical = $order->clinical_info;
            if (!$clinical && $order->medicalRecord?->relationLoaded('diagnoses') && $order->medicalRecord->diagnoses->isNotEmpty()) {
                $clinical = $order->medicalRecord->diagnoses
                    ->map(fn ($dx) => trim($dx->cie10_code.' '.$dx->cie10_description))->implode('; ');
            }
        @endphp
        @if ($clinical)
            <div class="section-label">Diagnóstico / Indicación clínica</div>
            <div class="box">{{ $clinical }}</div>
        @endif

        {{-- ============ EXÁMENES SOLICITADOS ============ --}}
        <div class="section-label">Exámenes solicitados</div>
        @foreach ($grouped as $category => $items)
            <div class="group">
                <div class="group-title">{{ $category }}</div>
                @foreach ($items as $item)
                    <div class="item">
                        <span class="chk"></span>{{ $item->name }}
                        @if ($item->notes)<span class="item-note"> — {{ $item->notes }}</span>@endif
                    </div>
                @endforeach
            </div>
        @endforeach

        {{-- ============ OTROS EXÁMENES ============ --}}
        @if ($order->notes)
            <div class="section-label">Otros exámenes / Observaciones</div>
            <div class="box">{{ $order->notes }}</div>
        @endif

        {{-- ============ FIRMA ============ --}}
        <table class="signature">
            <tr>
                <td>
                    @if ($doctor?->uses_physical_stamp_signature)
                        <div class="physical-signature-space"><div class="sign-line"></div></div>
                    @else
                    @if ($signature)
                        <img class="sign-img" src="{{ $signature }}" alt="firma"><br>
                    @endif
                    <div class="sign-line">
                        <span class="sign-name">{{ \App\Support\LetterheadData::doctorName($doctor) }}</span><br>
                        <span class="sign-meta">
                            @php
                                $allSpecialties = $professionalTitle ?? $doctor?->primarySpecialty()?->name;
                            @endphp
                            @if ($allSpecialties) {{ $allSpecialties }}<br>@endif
                            @if ($doctor?->medical_license) Registro Profesional MSP: {{ $doctor->medical_license }}<br>@endif
                            @if ($doctor?->acess_code) Registro ACESS: {{ $doctor->acess_code }} @endif
                            @if ($doctor?->acess_code && $doctor?->senescyt_registration) &nbsp;·&nbsp; @endif
                            @if ($doctor?->senescyt_registration) Registro SENESCYT: {{ $doctor->senescyt_registration }} @endif
                            @php $reach = array_filter([$signatureContact['phone'] ?? null, $signatureContact['email'] ?? null]); @endphp
                            @if ($reach)<br>{{ implode(' · ', $reach) }}@endif
                        </span>
                    </div>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    @if (!empty($settings['footer']))
        <div class="footer">{{ $settings['footer'] }}</div>
    @endif
</body>
</html>
