{{--
    Hoja diseñada: la papelería que la clínica ya usa, puesta detrás del
    contenido. `position: fixed` hace que dompdf la repita en TODAS las páginas
    y embeba la imagen una sola vez, así un documento de tres páginas no pesa
    el triple.

    Cuando el modo es "margins" no se dibuja nada: la clínica imprime sobre el
    papel físico y solo necesita que el contenido caiga dentro del diseño.
--}}
{{--
    En la receta (A4 horizontal con dos mitades) la hoja va UNA VEZ POR MITAD,
    a tamaño A5: A4 y A5 tienen la misma proporción, así que la misma imagen
    encaja exacta. Antes se estiraba una A4 vertical detrás de la hoja horizontal.
--}}
@if (!empty($sheetImage))
    @if (($sheetLayout ?? 'a4') === 'a5-pair')
        <img class="sheet-bg half" src="{{ $sheetImage }}" alt="">
        <img class="sheet-bg half right" src="{{ $sheetImage }}" alt="">
    @else
        <img class="sheet-bg" src="{{ $sheetImage }}" alt="">
    @endif
@endif
@if (!empty($isSample))
    {{-- Vista previa de Ajustes: nunca debe poder confundirse con un documento real. --}}
    <div class="sample-mark">MUESTRA</div>
@endif
