import { describe, expect, it } from 'vitest';
import { CASHIER_PENDING_COPY, CLINIC_DOCTOR_HANDOFF_COPY } from './billingFlowCopy';

describe('billing flow copy', () => {
  it('separates emitting the invoice from registering its payment', () => {
    expect(CASHIER_PENDING_COPY.action).toBe('Emitir factura');
    expect(CASHIER_PENDING_COPY.nextStep).toContain('Registrar cobro');
  });

  it('tells a clinic doctor where the completed attention is handed off', () => {
    expect(CLINIC_DOCTOR_HANDOFF_COPY.title).toBe('Atención enviada a caja');
    expect(CLINIC_DOCTOR_HANDOFF_COPY.description).toContain('Atenciones pendientes');
  });
});
