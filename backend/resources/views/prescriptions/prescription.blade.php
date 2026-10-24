<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Receta médica</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        @page { margin: 0; }
        body { margin: 0; color: #111; font-size: 11px; }
        .page { padding: 16px 18px; }

        /* ---- Hoja de dos paneles ---- */
        .sheet { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .panel { width: 49%; vertical-align: top; padding: 0 12px; }
        .cut { width: 2%; border-left: 1px dashed #9aa0a6; }
        .cut-mark { font-size: 8px; color: #9aa0a6; text-align: center; }

        /* ---- Relleno: arriba el contenido, al pie observaciones | firma ---- */
        .fill { width: 100%; border-collapse: collapse; }
        .fill > tr > td, .fill td.fill-top, .fill td.fill-bottom { padding: 0; }
        .fill-top { vertical-align: top; }
        .fill-bottom { vertical-align: bottom; }
        .foot { width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 8px; }
        .foot td { vertical-align: bottom; padding: 0; }
        .foot-obs { width: 58%; padding-right: 10px !important; vertical-align: top !important; }
        .foot-sign { width: 42%; }

        /* ---- Membrete: una quinta parte de la hoja ---- */
        .head { width: 100%; border-collapse: collapse; border-bottom: 2.5px solid #1F4E79; }
        .head td { vertical-align: middle; padding: 0 0 6px 0; }
        .logo { text-align: left; }
        .head-text { padding-left: 12px !important; }
        .head-name { font-size: 20px; font-weight: bold; color: #1F4E79; line-height: 1.2; }
        .head-sub { font-size: 12px; color: #1F4E79; margin-top: 2px; }
        .head-contact { font-size: 11px; color: #333; margin-top: 5px; line-height: 1.5; }
        .head-contact b { color: #1F4E79; }
        .head-img { width: 100%; border-collapse: collapse; border-bottom: 2.5px solid #1F4E79; }
        .head-img td { text-align: center; vertical-align: middle; padding: 0 0 5px 0; }

        .code { text-align: right; margin-top: 6px; font-size: 11px; color: #1F4E79; }
        .code b { font-size: 14px; }

        /* ---- Datos ---- */
        .meta { margin-top: 4px; font-size: 11px; line-height: 1.6; }
        .meta b { color: #1F4E79; }

        .sec { margin-top: 10px; }
        .sec-title { font-size: 11px; font-weight: bold; color: #1F4E79; text-transform: uppercase; letter-spacing: .3px; }

        /* ---- Medicamentos (panel izq.) ---- */
        .rx { width: 100%; border-collapse: collapse; margin-top: 2px; font-size: 11px; }
        .rx th { text-align: left; color: #1F4E79; font-size: 10px; text-transform: uppercase; padding: 2px 3px; }
        .rx td { vertical-align: top; padding: 5px 3px; line-height: 1.45; }
        .rx .num { color: #1F4E79; font-weight: bold; width: 16px; }
        .rx .med b { text-transform: uppercase; }
        .rx .brand { font-weight: bold; color: #333; }
        .rx .note { color: #333; }
        .rx .qty { width: 76px; }
        .rx .qty b { white-space: nowrap; }
        .rx .qty span { color: #555; font-size: 9px; }
        .ctrl { color: #c00; font-weight: bold; font-size: 9px; }

        /* ---- Indicaciones (panel der.) ---- */
        .ind-item { margin-top: 6px; line-height: 1.45; }
        .ind-head { font-weight: bold; }
        .ind-brand { font-weight: bold; color: #333; }
        .ind-body { color: #222; }

        /* ---- Observaciones ---- */
        .obs { font-size: 10.5px; margin-top: 2px; line-height: 1.5; }
        .obs .k { color: #1F4E79; font-weight: bold; }
        .obs .v { white-space: pre-line; }

        /* ---- Firma ---- */
        .sign { text-align: center; page-break-inside: avoid; }
        .sign img { height: 48px; }
        .sign .line { border-top: 1px solid #222; margin: 2px 4px 0; padding-top: 4px; }
        .sign .name { font-weight: bold; font-size: 11.5px; }
        .sign .sign-meta { font-size: 10px; color: #333; line-height: 1.45; margin: 0; }
        /* Sized from PrescriptionLayout, which counts it when fitting the page. */
        .physical-signature-space { height: {{ \App\Support\PrescriptionLayout::STAMP_SPACE_PX }}px; padding-top: {{ \App\Support\PrescriptionLayout::STAMP_PADDING_PX }}px; }

        /* ---- Vertical: una A4 sin cortar ---- */
        .page.portrait { font-size: 13px; }
        .page.portrait .head-name { font-size: 26px; }
        .page.portrait .head-sub { font-size: 14px; }
        .page.portrait .head-contact { font-size: 13px; }
        .page.portrait .code { font-size: 12.5px; }
        .page.portrait .code b { font-size: 16px; }
        .page.portrait .meta,
        .page.portrait .rx { font-size: 13px; }
        .page.portrait .obs { font-size: 12.5px; }
        .page.portrait .sec-title { font-size: 12.5px; }
        .page.portrait .rx th { font-size: 11.5px; }
        .page.portrait .rx .qty { width: 96px; }
        .page.portrait .rx .qty span,
        .page.portrait .ctrl { font-size: 10.5px; }
        .page.portrait .sec { margin-top: 14px; }
        .page.portrait .sign img { height: 60px; }
        .page.portrait .sign .name { font-size: 13.5px; }
        .page.portrait .sign .sign-meta { font-size: 12px; }

        /* ---- Media: un paso por debajo de la grande ---- */
        .page.medium .meta,
        .page.medium .rx { font-size: 10px; }
        .page.medium .obs,
        .page.medium .sec-title { font-size: 9.5px; }
        .page.portrait.medium .meta,
        .page.portrait.medium .rx { font-size: 11.5px; }
        .page.portrait.medium .obs,
        .page.portrait.medium .sec-title { font-size: 11px; }
        .page.medium .rx td { padding: 4px 3px; }
        .page.medium .sec,
        .page.portrait.medium .sec { margin-top: 9px; }
        .page.medium .sign img { height: 42px; }
        .page.portrait.medium .sign img { height: 52px; }

        /* ---- Compacta: recetas de varios medicamentos ---- */        .page.compact,
        .page.portrait.compact { font-size: 9px; }
        .page.compact .head-name { font-size: 15px; }
        .page.compact .head-sub,
        .page.compact .head-contact { font-size: 9px; }
        .page.compact .code { font-size: 9px; }
        .page.compact .code b { font-size: 11px; }
        .page.compact .meta,
        .page.compact .rx,
        .page.compact .obs,
        .page.compact .sec-title { font-size: 9px; }
        .page.portrait.compact .meta,
        .page.portrait.compact .rx,
        .page.portrait.compact .obs,
        .page.portrait.compact .sec-title { font-size: 10px; }
        .page.compact .meta { line-height: 1.5; }
        .page.compact .rx th { font-size: 8px; }
        .page.compact .rx td { padding: 3px 3px; line-height: 1.35; }
        .page.compact .rx .qty span,
        .page.compact .ctrl { font-size: 8px; }
        .page.compact .sec,
        .page.portrait.compact .sec { margin-top: 7px; }
        .page.compact .ind-item { margin-top: 4px; line-height: 1.35; }
        .page.compact .obs { line-height: 1.4; }
        .page.compact .sign img { height: 36px; }
        .page.portrait.compact .sign img { height: 44px; }
        .page.compact .sign .name { font-size: 9.5px; }
        .page.compact .sign .sign-meta { font-size: 8.5px; }

        /* ---- Ajustada: recetas muy largas, todo en una hoja ---- */
        .page.tight .meta,
        .page.tight .rx,
        .page.tight .obs,
        .page.tight .sec-title,
        .page.portrait.tight .meta,
        .page.portrait.tight .rx,
        .page.portrait.tight .obs,
        .page.portrait.tight .sec-title { font-size: 7.5px; }
        .page.tight .rx th { font-size: 6.5px; }
        .page.tight .rx td { padding: 1.5px 3px; line-height: 1.3; }
        .page.tight .meta { line-height: 1.35; }
        .page.tight .sec,
        .page.portrait.tight .sec { margin-top: 4px; }
        .page.tight .ind-item { margin-top: 2px; line-height: 1.3; }
        .page.tight .obs { line-height: 1.3; }
        .page.tight .sign img,
        .page.portrait.tight .sign img { height: 28px; }
        .page.tight .sign .name { font-size: 8.5px; }
        .page.tight .sign .sign-meta { font-size: 7.5px; }
        @include('partials.pdf-sheet-styles', ['sheetLayout' => ($portrait ?? false) ? 'a4' : 'a5-pair'])
    </style>
</head>
@php
    $portrait = $portrait ?? false;
    $sexLabel = match ($patient?->gender) { 'male' => 'Masculino', 'female' => 'Femenino', default => '—' };
    $fechaLugar = (!empty($issueCity) ? \Illuminate\Support\Str::upper($issueCity).' ' : '').($date?->format('d-m-Y') ?? '');

    // Precompute each prescription line once (shared by both panels).
    $items = [];
    foreach ($record->prescriptions as $rx) {
        $routeLabel = $rx->route ? match ($rx->route) {
            'oral' => 'VO', 'sublingual' => 'SL', 'iv' => 'IV', 'im' => 'IM', 'sc' => 'SC',
            'topical' => 'Tópico', 'inhaled' => 'Inhalado', 'rectal' => 'Rectal', default => $rx->route,
        } : null;
        $posology = trim(
            ($rx->dosage ?? '')
            . ($routeLabel ? " ({$routeLabel})" : '')
            . ($rx->frequency ? ", {$rx->frequency}" : '')
            . ($rx->duration_days ? ", por {$rx->duration_days} días" : '')
        );
        // A prescription written after 2026-09-21 usually carries no structured
        // posology at all — just a name, a quantity and free-text instructions.
        // The indications line then IS the instructions; otherwise `extra`
        // keeps them as the second half of the line on the patient's panel.
        $items[] = [
            'name'    => \Illuminate\Support\Str::upper($rx->medication_name).($rx->concentration ? ' '.$rx->concentration : ''),
            'brand'   => $rx->generic_name,
            'qty'     => $rx->quantity,
            'qtyWords'=> $rx->quantity ? \Illuminate\Support\Str::upper(\App\Support\SpanishNumber::words((int) $rx->quantity)) : null,
            'ctrl'    => (bool) $rx->is_controlled,
            'ind'     => $posology !== '' ? $posology : trim((string) $rx->instructions),
            'extra'   => $posology !== '' ? $rx->instructions : null,
        ];
    }

    $diagnoses = $record->relationLoaded('diagnoses') ? $record->diagnoses : collect();
    // The visit snapshots the description chosen by its author. Legacy visits
    // without a snapshot retain the primary specialty as a fallback.
    $specialty = $record->professional_title ?: $doctor?->primarySpecialty()?->name;

    // ── Page geometry (mm) ─────────────────────────────────────────────────
    // Vertical room once the margins in play are taken off: the designed
    // sheet's (scaled for the A5 halves) or the page padding, plus slack so
    // dompdf never spills the foot onto a second page.
    $pxToMm = \App\Support\PrescriptionLayout::PX_TO_MM;
    $pageHeight = $portrait ? 297 : 210;
    $usesSheet = ($sheetMode ?? 'off') !== 'off';
    $sheetM = $sheetMargins ?? \App\Support\LetterheadData::DEFAULT_SHEET_MARGINS;
    $verticalPad = $usesSheet
        ? ($sheetM['top'] + $sheetM['bottom']) * ($portrait ? 1 : 0.7071)
        : 2 * 16 * $pxToMm;
    $available = round(max(80, $pageHeight - $verticalPad - 14), 1);
    $boxWidth = $portrait ? 186 : 128;

    // The letterhead takes a fifth of the sheet, unless the clinic set the
    // banner's height itself in Ajustes → Papelería.
    $headerBox = isset($settings['header_image_height']) && is_numeric($settings['header_image_height'])
        ? max(10, min(80, (float) $settings['header_image_height']))
        : round($pageHeight / 5 - 6, 1);
    // An image is scaled to fit that box without distortion: as wide as the
    // half allows, as tall as the box allows, whichever runs out first.
    $bannerSize = null;
    if ($headerMode === 'image' && !empty($headerImageRatio)) {
        $w = min($boxWidth, $headerBox * $headerImageRatio);
        $bannerSize = ['w' => round($w, 1), 'h' => round($w / $headerImageRatio, 1)];
    }
    $logoSize = null;
    if (!empty($logo) && !empty($logoRatio)) {
        $logoH = $headerBox - 4;
        $logoW = min($boxWidth * 0.32, $logoH * $logoRatio);
        $logoSize = ['w' => round($logoW, 1), 'h' => round($logoW / $logoRatio, 1)];
    }
    $headerMm = match ($headerMode) {
        'none' => 0.0,
        'image' => ($bannerSize['h'] ?? $headerBox) + 4,
        default => $headerBox + 4,
    };

    $observationLines = array_values(array_filter([
        $record->prescriptions->isNotEmpty() && ! empty($record->treatment) ? 'Plan / Tratamiento: '.$record->treatment : null,
        $record->recommendations ? 'Recomendaciones: '.$record->recommendations : null,
        $record->alarm_signs ? 'Signos de alarma: '.$record->alarm_signs : null,
        'Alergias: '.($allergies ?: 'No refiere'),
        'Próxima cita:',
    ]));

    // Largest type that still fits one sheet, and whether the fixed-height top
    // is safe at all (see App\Support\PrescriptionLayout).
    $layout = \App\Support\PrescriptionLayout::choose(
        $items, $observationLines, max(1, $diagnoses->count()), $portrait, $headerMm, $available, !empty($signature), (bool) ($doctor?->uses_physical_stamp_signature),
    );
    $pageClass = trim(($portrait ? 'portrait ' : '').match ($layout['density']) { 'medium' => 'medium', 'compact' => 'compact', 'tight' => 'compact tight', default => '' });
    $topStyle = $layout['fill'] ? ' style="height: '.$layout['top'].'mm;"' : '';
@endphp
<body>
@include('partials.pdf-sheet', ['sheetLayout' => $portrait ? 'a4' : 'a5-pair'])
    <div class="page {{ $pageClass }}">
        @if ($portrait)
            {{-- Vertical: una sola hoja A4, sin cortar. El encabezado y los datos
                 van una vez; debajo, la receta y luego las indicaciones. --}}
            <table class="fill">
                <tr><td class="fill-top"{!! $topStyle !!}>
                    @include('prescriptions.parts.header')
                    @include('prescriptions.parts.medications')
                    @include('prescriptions.parts.indications')
                </td></tr>
                <tr><td class="fill-bottom">@include('prescriptions.parts.foot')</td></tr>
            </table>
        @else
            {{-- Horizontal: dos mitades para cortar, receta | indicaciones. --}}
            <table class="sheet">
                <tr>
                    @foreach (['left', 'right'] as $idx => $side)
                        @if ($idx === 1)
                            <td class="cut"><div class="cut-mark">✂</div></td>
                        @endif
                        <td class="panel">
                            <table class="fill">
                                <tr><td class="fill-top"{!! $topStyle !!}>
                                    @include('prescriptions.parts.header')
                                    @include($side === 'left' ? 'prescriptions.parts.medications' : 'prescriptions.parts.indications')
                                </td></tr>
                                <tr><td class="fill-bottom">@include('prescriptions.parts.foot')</td></tr>
                            </table>
                        </td>
                    @endforeach
                </tr>
            </table>
        @endif
    </div>
</body>
</html>
