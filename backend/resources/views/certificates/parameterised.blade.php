<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $certificate->title }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        @page { margin: 0; }
        body { margin: 0; color: #111; font-size: 11.5px; }
        .page { padding: 34px 46px 40px; }
        @include('partials.pdf-letterhead-styles')

        .head-img { text-align: center; padding-bottom: 6px; }
        .head-img img { max-width: 100%; max-height: 90px; }
        @include('partials.pdf-sheet-styles')

        .doc-title {
            margin-top: 26px; margin-bottom: 26px;
            font-size: 16px; font-weight: bold; letter-spacing: 0.6px;
            text-transform: uppercase; text-align: center; color: #111;
        }
        /* The body of this certificate is a list of labelled parameters, one per
           line — that IS the format an employer / the IESS expects. */
        .place-date { margin-bottom: 14px; text-transform: uppercase; }
        .line { margin-bottom: 9px; line-height: 1.5; text-align: justify; text-transform: uppercase; }
        .line .lbl { font-weight: bold; }
        .intro { margin-bottom: 9px; line-height: 1.6; text-transform: uppercase; text-align: justify; }
        .closing { margin-top: 22px; text-transform: uppercase; }
        .sign-block { margin-top: 46px; text-align: center; }
        .sign-block .sign-img { max-height: 70px; max-width: 220px; }
        .sign-rule { width: 260px; margin: 4px auto 6px; border-bottom: 1px solid #444; }
        .sign-name { font-weight: bold; font-size: 11.5px; text-transform: uppercase; }
        .sign-meta { font-size: 10.5px; line-height: 1.5; }
        .physical-signature-space { height: 92px; }
    </style>
</head>
<body>
@include('partials.pdf-sheet')
<div class="page">

    {{-- Membrete: banner subido, el membrete digital, o ninguno cuando la hoja
         diseñada ya lo trae impreso. --}}
    @if ($headerMode === 'none')
        {{-- La hoja lo trae. --}}
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
                    </div>
                </td>
            </tr>
        </table>
    @endif

    <div class="doc-title">Certificado médico</div>

    <div class="place-date">
        {{ $certificate->city ?: ($workspace?->city ?? '') }},
        {{ $issuedLong }}
    </div>

    <div class="intro">
        Certifico que {{ $patientArticle }} <b>{{ $patient?->name }}</b>
        @if ($patientAge !== null) de <b>{{ $patientAge }}</b> años de edad, @endif
        con <b>C.I: {{ $patient?->id_number ?? '—' }}</b> fue atendid{{ $patientVowel }} en esta casa de salud,
        con los siguientes parámetros:
    </div>

    @if ($certificate->hc_number)
        <div class="line"><span class="lbl">Historia clínica:</span> {{ $certificate->hc_number }}</div>
    @endif
    @if ($certificate->address)
        <div class="line"><span class="lbl">Dirección:</span> {{ $certificate->address }}</div>
    @endif
    @if ($certificate->phone)
        <div class="line"><span class="lbl">Teléfono:</span> {{ $certificate->phone }}</div>
    @endif
    @if ($certificate->contingency_type)
        <div class="line"><span class="lbl">Tipo de contingencia:</span> {{ $certificate->contingency_type }}</div>
    @endif
    @if ($certificate->company)
        <div class="line"><span class="lbl">Empresa:</span> {{ $certificate->company }}</div>
    @endif
    @if ($certificate->activity)
        <div class="line"><span class="lbl">Actividad:</span> {{ $certificate->activity }}</div>
    @endif
    @if ($attendedShort)
        <div class="line">
            <span class="lbl">Fecha de atención:</span> {{ $attendedShort }} ({{ $attendedLong }})
        </div>
    @endif
    @if ($certificate->symptoms)
        <div class="line"><span class="lbl">Síntomas:</span> {{ $certificate->symptoms }}</div>
    @endif
    @if ($certificate->diagnosis)
        <div class="line"><span class="lbl">Diagnóstico de atención:</span> {{ $certificate->diagnosis }}</div>
    @endif
    @if ($certificate->cie10_codes && ($certificate->show_cie10 ?? true))
        <div class="line"><span class="lbl">CIE 10:</span> {{ $certificate->cie10_codes }}</div>
    @endif
    @if ($certificate->treatment)
        <div class="line"><span class="lbl">Tratamiento:</span> {{ $certificate->treatment }}</div>
    @endif
    @if ($certificate->absolute_rest !== null)
        <div class="line">
            <span class="lbl">Reposo absoluto:</span> {{ $certificate->absolute_rest ? 'SÍ' : 'NO' }}
        </div>
    @endif
    @if ($restDays)
        <div class="line"><span class="lbl">Días de reposo:</span> {{ $restDays }} ({{ $restDaysWords }}) {{ $restDays === 1 ? 'día' : 'días' }}</div>
    @endif
    @if ($restFromShort)
        <div class="line"><span class="lbl">Desde:</span> {{ $restFromShort }} ({{ $restFromLong }})</div>
    @endif
    @if ($restUntilShort)
        <div class="line"><span class="lbl">Hasta el:</span> {{ $restUntilShort }} ({{ $restUntilLong }})</div>
    @endif

    @if (trim((string) $certificate->body) !== '')
        <div class="line">{{ $certificate->body }}</div>
    @endif

    <div class="closing">Es todo en cuanto puedo certificar en honor a la verdad.</div>
    <div class="closing">Atentamente,</div>

    <div class="sign-block">
        @if ($doctor?->uses_physical_stamp_signature)
            <div class="physical-signature-space"><div class="sign-rule"></div></div>
        @else
        @if ($signature)
            <img class="sign-img" src="{{ $signature }}" alt="firma"><br>
        @endif
        <div class="sign-rule"></div>
        <div class="sign-name">{{ \App\Support\LetterheadData::doctorName($doctor) }}</div>
        <div class="sign-meta">
            @if ($doctor?->id_number) CI: {{ $doctor->id_number }}<br> @endif
            @if (!empty($signatureContact['email'])) E-MAIL: {{ $signatureContact['email'] }}<br> @endif
            @if (!empty($signatureContact['phone'])) TLF.: {{ $signatureContact['phone'] }}<br> @endif
            @php
                $specialties = $professionalTitle ?? $doctor?->primarySpecialty()?->name;
            @endphp
            @if ($specialties) {{ $specialties }} @endif
            @if ($doctor?->medical_license) &nbsp;·&nbsp; Reg. MSP: {{ $doctor->medical_license }} @endif
            @if ($doctor?->acess_code) &nbsp;·&nbsp; ACESS: {{ $doctor->acess_code }} @endif
        </div>
        @endif
    </div>

    @if (!empty($settings['footer']))
        <div class="footer">{{ $settings['footer'] }}</div>
    @endif
</div>
</body>
</html>
