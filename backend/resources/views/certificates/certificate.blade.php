<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $certificate->title }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        @page { margin: 0; }
        body { margin: 0; color: #1a1a1a; font-size: 12px; }
        .page { padding: 32px 40px; }
        @include('partials.pdf-letterhead-styles')
        .doc-title { margin-top: 28px; font-size: 17px; font-weight: bold; color: #1F4E79;
                     text-align: center; text-transform: uppercase; letter-spacing: 0.5px; }
        .doc-sub { margin-top: 6px; font-size: 11px; color: #555; text-align: center; }
        .section-label { margin-top: 22px; font-size: 11px; font-weight: bold; color: #1F4E79;
                         text-transform: uppercase; letter-spacing: 1px; }
        .box { margin-top: 8px; padding: 14px 16px; border: 1px solid #d9d9d9; border-radius: 4px;
               background: #fafafa; min-height: 120px; white-space: pre-line; line-height: 1.6; font-size: 12px; }
        .meta-extra { margin-top: 14px; font-size: 11.5px; line-height: 1.7; }
        .meta-extra .lbl { font-weight: bold; color: #1F4E79; }
        .head-img { text-align: center; padding-bottom: 6px; }
        .head-img img { max-width: 100%; max-height: 90px; }
        @include('partials.pdf-sheet-styles')
    </style>
</head>
<body>
@include('partials.pdf-sheet')
<div class="page">

    @include('partials.pdf-letterhead', [
        'logo' => $logo,
        'settings' => $settings,
        'workspace' => $workspace,
        'patient' => $patient,
        'date' => $date,
    ])

    <div class="doc-title">{{ $certificate->title }}</div>
    <div class="doc-sub">{{ $certificate->typeLabel() }}</div>

    @if ($certificate->rest_days || $certificate->valid_from || $certificate->valid_until)
        <div class="meta-extra">
            @if ($certificate->rest_days)
                <div><span class="lbl">Días de reposo:</span> {{ $certificate->rest_days }} día(s)</div>
            @endif
            @if ($certificate->valid_from)
                <div><span class="lbl">Desde:</span> {{ $certificate->valid_from->format('d/m/Y') }}</div>
            @endif
            @if ($certificate->valid_until)
                <div><span class="lbl">Hasta:</span> {{ $certificate->valid_until->format('d/m/Y') }}</div>
            @endif
        </div>
    @endif

    <div class="section-label">Contenido del certificado</div>
    <div class="box">{{ $certificate->body }}</div>

    @if ($certificate->medicalRecord)
        <div class="meta-extra" style="margin-top: 16px; font-size: 10px; color: #666;">
            Referencia: consulta del {{ $certificate->medicalRecord->created_at?->format('d/m/Y') }}
            — {{ $certificate->medicalRecord->reason_for_consultation }}
        </div>
    @endif

    @include('partials.pdf-signature', [
        'signature' => $signature,
        'doctor' => $doctor,
        'settings' => $settings,
    ])
</div>
</body>
</html>
