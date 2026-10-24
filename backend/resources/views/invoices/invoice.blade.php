<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Factura {{ $invoice->invoice_number }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        @page { margin: 0; }
        body { margin: 0; color: #1a1a1a; font-size: 11px; }
        .page { padding: 32px 40px 60px 40px; }

        /* ---- Membrete ---- */
        .header { width: 100%; border-bottom: 2px solid #1F4E79; padding-bottom: 12px; }
        .header td { vertical-align: middle; }
        .logo img { max-width: 90px; max-height: 90px; }
        .head-text { padding-left: 14px; }
        .head-name { font-size: 18px; font-weight: bold; color: #1F4E79; }
        .head-sub  { font-size: 10px; color: #444; margin-top: 2px; }
        .head-contact { font-size: 9px; color: #666; margin-top: 4px; }

        /* ---- Cabecera de factura ---- */
        .invoice-header { width: 100%; margin-top: 18px; }
        .invoice-title  { font-size: 22px; font-weight: bold; color: #1F4E79; }
        .invoice-meta   { font-size: 11px; }
        .invoice-meta td { padding: 2px 4px; }
        .invoice-meta .lbl { font-weight: bold; color: #1F4E79; text-align: right; }
        .invoice-meta .val { min-width: 110px; }

        /* ---- Datos del cliente ---- */
        .section-label { margin-top: 20px; font-size: 10px; font-weight: bold;
                         color: #1F4E79; text-transform: uppercase; letter-spacing: 1px;
                         border-bottom: 1px solid #d9d9d9; padding-bottom: 3px; }
        .customer { width: 100%; margin-top: 6px; font-size: 11px; }
        .customer td { padding: 2px 0; }
        .customer .lbl { font-weight: bold; color: #555; width: 80px; }

        /* ---- Tabla de ítems ---- */
        .items { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .items th { background-color: #1F4E79; color: #ffffff; font-size: 10px;
                    padding: 5px 6px; text-align: left; }
        .items th.r { text-align: right; }
        .items td { padding: 5px 6px; font-size: 11px; border-bottom: 1px solid #eeeeee; }
        .items td.r { text-align: right; }
        .items tr.alt td { background-color: #f5f8fc; }

        /* ---- Totales ---- */
        .totals-wrap { width: 100%; margin-top: 10px; }
        .totals { border-collapse: collapse; float: right; font-size: 11px; min-width: 220px; }
        .totals td { padding: 3px 6px; }
        .totals .lbl { text-align: right; color: #555; }
        .totals .val { text-align: right; font-weight: bold; min-width: 80px; }
        .totals .grand .lbl,
        .totals .grand .val { font-size: 13px; color: #1F4E79; border-top: 2px solid #1F4E79;
                               padding-top: 5px; }
        .totals .due .val { color: #c05621; }
        .totals .paid-ok .val { color: #276749; }
        .clearfix { clear: both; }

        /* ---- Pagos registrados ---- */
        .payments { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 10px; }
        .payments th { font-size: 10px; font-weight: bold; color: #555;
                       border-bottom: 1px solid #cccccc; padding: 3px 4px; text-align: left; }
        .payments th.r { text-align: right; }
        .payments td { padding: 3px 4px; border-bottom: 1px solid #eeeeee; }
        .payments td.r { text-align: right; }

        /* ---- Notas ---- */
        .notes-box { margin-top: 6px; padding: 8px 10px; border: 1px solid #d9d9d9;
                     border-radius: 4px; background: #fafafa; font-size: 10px;
                     white-space: pre-line; line-height: 1.5; }

        /* ---- Pie fijo ---- */
        .footer { position: fixed; bottom: 0; left: 0; right: 0;
                  padding: 7px 40px; border-top: 1px solid #e0e0e0;
                  font-size: 9px; color: #888; text-align: center; }

        /* ---- Bloque SRI (RIDE) ---- */
        .sri-box { width: 100%; margin-top: 14px; border: 1px solid #1F4E79;
                   border-radius: 4px; }
        .sri-box td { vertical-align: top; padding: 8px 10px; }
        .sri-data { font-size: 9.5px; line-height: 1.55; }
        .sri-data .lbl { font-weight: bold; color: #1F4E79; }
        .sri-clave { font-family: DejaVu Sans Mono, monospace; font-size: 8.5px;
                     word-break: break-all; letter-spacing: 0.3px; }
        .sri-qr { width: 110px; text-align: center; border-left: 1px solid #e0e0e0; }
        .sri-qr img { width: 95px; height: 95px; }
        .sri-authorized { color: #276749; font-weight: bold; }
        .internal-badge { margin-top: 12px; padding: 5px 10px; border: 1px dashed #c05621;
                          color: #c05621; font-size: 10px; font-weight: bold;
                          border-radius: 4px; text-align: center; letter-spacing: 1px; }
    </style>
</head>
<body>
<div class="page">

    {{-- ============================================================
         MEMBRETE  (idéntico al de la receta médica)
         ============================================================ --}}
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

    {{-- ============================================================
         CABECERA DE FACTURA
         ============================================================ --}}
    <table class="invoice-header">
        <tr>
            <td style="vertical-align: top;">
                <div class="invoice-title">FACTURA</div>
            </td>
            <td style="text-align: right; vertical-align: top;">
                <table class="invoice-meta">
                    <tr>
                        <td class="lbl">N° Factura:</td>
                        <td class="val"><strong>{{ $invoice->invoice_number }}</strong></td>
                    </tr>
                    <tr>
                        <td class="lbl">Fecha emisión:</td>
                        <td class="val">{{ $invoice->issued_at?->format('d/m/Y') ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="lbl">Estado:</td>
                        <td class="val">
                            {{ match($invoice->status) {
                                'draft'     => 'Borrador',
                                'issued'    => 'Emitida',
                                'partial'   => 'Pago parcial',
                                'paid'      => 'Pagada',
                                'cancelled' => 'Anulada',
                                'void'      => 'Anulada (void)',
                                default     => $invoice->status,
                            } }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- ============================================================
         BLOQUE SRI (RIDE) — visible cuando la factura está autorizada
         ============================================================ --}}
    @if (!empty($invoice->sri_authorization_number))
    <table class="sri-box">
        <tr>
            <td class="sri-data">
                <div><span class="sri-authorized">AUTORIZADO POR EL SRI</span></div>
                <div><span class="lbl">RUC emisor:</span> {{ $workspace?->ruc ?? '—' }}</div>
                @if ($workspace?->razon_social)
                    <div><span class="lbl">Razón social:</span> {{ $workspace->razon_social }}</div>
                @endif
                <div><span class="lbl">N° Comprobante:</span> {{ $invoice->sri_sequence_number ?? '—' }}</div>
                <div><span class="lbl">N° Autorización:</span> {{ $invoice->sri_authorization_number }}</div>
                <div><span class="lbl">Fecha autorización:</span>
                    {{ $invoice->sri_authorization_date?->format('d/m/Y H:i') ?? '—' }}</div>
                <div><span class="lbl">Ambiente:</span>
                    {{ ($invoice->sri_environment === 'production') ? 'PRODUCCIÓN' : 'PRUEBAS' }}
                    &nbsp;·&nbsp; <span class="lbl">Emisión:</span> NORMAL</div>
                <div style="margin-top: 4px;"><span class="lbl">Clave de acceso:</span></div>
                <div class="sri-clave">{{ $invoice->sri_clave_acceso }}</div>
            </td>
            @if (!empty($sriQr ?? null))
            <td class="sri-qr">
                <img src="{{ $sriQr }}" alt="QR SRI">
            </td>
            @endif
        </tr>
    </table>
    @elseif (!$workspace?->sri_enabled)
    <div class="internal-badge">DOCUMENTO INTERNO · SIN VALIDEZ TRIBUTARIA</div>
    @endif

    {{-- ============================================================
         DATOS DEL CLIENTE
         ============================================================ --}}
    <div class="section-label">Datos del cliente</div>
    <table class="customer">
        <tr>
            <td class="lbl">Nombre:</td>
            <td>{{ $patient?->name ?? '—' }}</td>
            <td class="lbl" style="padding-left: 20px;">Cédula:</td>
            <td>{{ $patient?->id_number ?? '—' }}</td>
        </tr>
        @if ($patient?->city)
        <tr>
            <td class="lbl">Ciudad:</td>
            <td colspan="3">{{ $patient->city }}</td>
        </tr>
        @endif
        @if ($patient?->phone)
        <tr>
            <td class="lbl">Teléfono:</td>
            <td colspan="3">{{ $patient->phone }}</td>
        </tr>
        @endif
    </table>

    {{-- ============================================================
         DETALLE DE SERVICIOS
         ============================================================ --}}
    <div class="section-label" style="margin-top: 18px;">Detalle de servicios</div>
    <table class="items">
        <thead>
            <tr>
                <th style="width: 24px;">#</th>
                <th>Descripción</th>
                <th class="r" style="width: 60px;">Cant.</th>
                <th class="r" style="width: 100px;">P. Unit.</th>
                <th class="r" style="width: 100px;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items->sortBy('sort_order') as $i => $item)
            <tr @if ($i % 2 === 1) class="alt" @endif>
                <td>{{ $i + 1 }}</td>
                <td>{{ $item->description }}</td>
                <td class="r">{{ number_format((float) $item->quantity, 2) }}</td>
                <td class="r">${{ number_format((float) $item->unit_price, 2) }}</td>
                <td class="r">${{ number_format((float) $item->line_total, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    {{-- ============================================================
         TOTALES  (flotando a la derecha)
         ============================================================ --}}
    <div class="totals-wrap">
        <table class="totals">
            <tr>
                <td class="lbl">Subtotal:</td>
                <td class="val">${{ number_format((float) $invoice->subtotal, 2) }}</td>
            </tr>
            <tr class="grand">
                <td class="lbl">TOTAL:</td>
                <td class="val">${{ number_format((float) $invoice->total, 2) }}</td>
            </tr>
            @if ((float) $invoice->amount_paid > 0)
            <tr>
                <td class="lbl">Pagado:</td>
                <td class="val paid-ok" style="color: #276749;">
                    ${{ number_format((float) $invoice->amount_paid, 2) }}
                </td>
            </tr>
            @endif
            @if ((float) $invoice->amount_due > 0)
            <tr>
                <td class="lbl">Por cobrar:</td>
                <td class="val due" style="color: #c05621;">
                    ${{ number_format((float) $invoice->amount_due, 2) }}
                </td>
            </tr>
            @endif
        </table>
    </div>
    <div class="clearfix"></div>

    {{-- ============================================================
         HISTORIAL DE PAGOS  (si existen)
         ============================================================ --}}
    @if ($invoice->payments->isNotEmpty())
    <div class="section-label" style="margin-top: 20px;">Pagos registrados</div>
    <table class="payments">
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Método</th>
                <th>Referencia</th>
                <th class="r">Monto</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->payments->sortBy('payment_date') as $payment)
            <tr>
                <td>{{ $payment->payment_date?->format('d/m/Y') ?? '—' }}</td>
                <td>{{ match($payment->payment_method) {
                    'cash'     => 'Efectivo',
                    'transfer' => 'Transferencia',
                    'card'     => 'Tarjeta',
                    default    => 'Otro',
                } }}</td>
                <td>{{ $payment->reference ?? '—' }}</td>
                <td class="r">${{ number_format((float) $payment->amount, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    {{-- ============================================================
         NOTAS / OBSERVACIONES
         ============================================================ --}}
    @if (!empty($invoice->notes))
    <div class="section-label" style="margin-top: 18px;">Observaciones</div>
    <div class="notes-box">{{ $invoice->notes }}</div>
    @endif

</div>

{{-- ============================================================
     PIE DE PÁGINA  (fijo al fondo)
     ============================================================ --}}
@if (!empty($settings['footer']))
<div class="footer">{{ $settings['footer'] }}</div>
@else
<div class="footer">
    {{ $workspace?->name ?? '' }}
    @if (!empty($settings['address'])) &nbsp;·&nbsp; {{ $settings['address'] }} @endif
    @if (!empty($settings['phone'])) &nbsp;·&nbsp; {{ $settings['phone'] }} @endif
</div>
@endif

</body>
</html>
