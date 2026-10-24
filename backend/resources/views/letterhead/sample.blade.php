{{--
    Sample sheet for "Ver ejemplo" in Ajustes → Membrete e impresión.

    Composes the SAME partials the real clinical documents use, so what the
    owner sees here is what a receta or a certificado will actually print — if
    this drifts from `certificates/certificate.blade.php`, the preview starts
    lying, which is worse than having no preview at all.

    A4 portrait on purpose: that is the orientation the designed sheet is cut
    for (210×297mm in pdf-sheet-styles), so a clinic printing on its own paper
    can check the alignment here.

    Everything about the patient is invented; the letterhead, the signature and
    the professional registries are the workspace's real ones. Hence the
    watermark — this must never be mistakable for a clinical document.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Ejemplo de membrete</title>
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
               background: #fafafa; min-height: 120px; line-height: 1.6; font-size: 12px; }
        .head-img { text-align: center; padding-bottom: 6px; }
        .head-img img { max-width: 100%; max-height: 90px; }

        /* Diagonal band across the page. Sits above the designed sheet but below
           nothing else, so it survives printing and screenshots alike. */
        .sample-mark {
            position: fixed; top: 44%; left: -6%; width: 112%;
            transform: rotate(-24deg);
            text-align: center; font-size: 46px; font-weight: bold;
            color: #d92d20; opacity: 0.13; letter-spacing: 6px;
            z-index: 100;
        }
        .sample-note { margin-top: 18px; font-size: 10.5px; color: #666; text-align: center; }

        @include('partials.pdf-sheet-styles')
    </style>
</head>
<body>
@include('partials.pdf-sheet')

<div class="sample-mark">MUESTRA</div>

<div class="page">

    @include('partials.pdf-letterhead', [
        'logo' => $logo,
        'settings' => $settings,
        'workspace' => $workspace,
        'patient' => $patient,
        'date' => $date,
    ])

    <div class="doc-title">Ejemplo de documento</div>
    <div class="doc-sub">Así se verán tus recetas, órdenes y certificados</div>

    <div class="section-label">Contenido</div>
    <div class="box">
        Este documento es solo un ejemplo para revisar cómo queda tu membrete: el logo, los datos
        del consultorio, tu firma y el pie de página.<br><br>
        Los datos del paciente son ficticios y no corresponden a ninguna persona real. El documento
        no tiene validez clínica ni legal, y no queda registrado en ninguna historia clínica.
    </div>

    @include('partials.pdf-signature', [
        'signature' => $signature,
        'doctor' => $doctor,
        'settings' => $settings,
    ])

    <div class="sample-note">
        Documento de ejemplo generado desde Ajustes · Sin validez clínica
    </div>
</div>
</body>
</html>
