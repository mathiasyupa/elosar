/** Copy shared by the clinical closeout and the cashier queue.
 *
 * Issuing a fiscal document and registering money are deliberately separate
 * actions: a completed attention is handed to cash first, then the invoice
 * detail owns the payment step and its audit trail.
 */
export const CASHIER_PENDING_COPY = {
  heading: 'Atenciones pendientes de factura',
  action: 'Emitir factura',
  nextStep: 'Luego abre la factura para Registrar cobro.',
} as const;

export const CLINIC_DOCTOR_HANDOFF_COPY = {
  title: 'Atención enviada a caja',
  description:
    'Caja verá al paciente en Facturas → Atenciones pendientes para emitir la factura. La información clínica permanece en el expediente.',
} as const;

export const BILLING_MANAGER_CLOSEOUT_COPY =
  'La atención está lista para factura. Emitir factura crea el comprobante; después, abre la factura para registrar el cobro.';
