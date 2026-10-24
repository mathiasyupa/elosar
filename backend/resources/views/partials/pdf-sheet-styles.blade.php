{{-- Estilos de la hoja diseñada. Se incluye DESPUÉS del resto para que los
     márgenes de la clínica ganen sobre el padding por defecto del documento. --}}
@php
    $sheetMode = $sheetMode ?? 'off';
    $sheetMargins = $sheetMargins ?? \App\Support\LetterheadData::DEFAULT_SHEET_MARGINS;
    $sheetLayout = $sheetLayout ?? 'a4';
    // A5 is A4 scaled by 1/√2: the margins drawn on the A4 sheet shrink with it.
    $halfScale = 0.7071;
@endphp

.sheet-bg {
    position: fixed;
    top: 0; left: 0;
    width: 210mm; height: 297mm;
    z-index: -100;
}

.sheet-bg.half { width: 148.5mm; height: 210mm; }
.sheet-bg.half.right { left: 148.5mm; }

.sample-mark {
    position: fixed; top: 44%; left: -6%; width: 112%;
    transform: rotate(-24deg);
    text-align: center; font-size: 46px; font-weight: bold;
    color: #d92d20; opacity: 0.13; letter-spacing: 6px;
    z-index: 100;
}

@if ($sheetMode !== 'off' && $sheetLayout === 'a5-pair')
    /* Receta: cada mitad es una hoja A5; los márgenes van por mitad. */
    .page { padding: 0; }
    .panel {
        padding: {{ round($sheetMargins['top'] * $halfScale, 1) }}mm {{ round($sheetMargins['right'] * $halfScale, 1) }}mm
                 {{ round($sheetMargins['bottom'] * $halfScale, 1) }}mm {{ round($sheetMargins['left'] * $halfScale, 1) }}mm;
    }
@elseif ($sheetMode !== 'off')
    /* El contenido se mete dentro del diseño de la hoja. */
    .page {
        padding: {{ $sheetMargins['top'] }}mm {{ $sheetMargins['right'] }}mm
                 {{ $sheetMargins['bottom'] }}mm {{ $sheetMargins['left'] }}mm;
    }
    /* El pie fijo del documento se sube por encima del pie de la hoja. */
    .footer { bottom: {{ max(0, $sheetMargins['bottom'] - 6) }}mm; }
@endif
