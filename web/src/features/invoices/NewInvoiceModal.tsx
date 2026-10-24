import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { createInvoice } from '@/api/invoices';
import { fetchMedicalServices } from '@/api/catalogs';
import { fetchBillingDoctors } from '@/api/billing';
import { PatientPicker } from '@/components/PatientPicker';
import { BillingPatientPicker } from '@/components/BillingPatientPicker';
import { Modal } from '@/components/ui/Modal';
import { Icon } from '@/components/Icon';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { formatMoney } from '@/i18n/es';
import { ecuadorDateInputValue } from '@/lib/ecuadorDate';
import type {
  BillableService,
  BillingPatient,
  Invoice,
  InvoiceInput,
  InvoiceItemInput,
  Patient,
} from '@/api/types';

interface Row extends InvoiceItemInput {
  key: number;
}

let keySeq = 1;
// Health services in Ecuador are IVA-exempt, so items carry no VAT.
const emptyRow = (): Row => ({ key: keySeq++, description: '', quantity: '1', unit_price: '0' });

/**
 * Seeds the invoice with what the doctor declared as performed.
 *
 * A service the doctor could not price (clinic doctors hold no billing.manage)
 * arrives as 0 rather than being dropped: the cash desk must see that the work
 * happened and decide what it costs, not discover the omission later.
 */
function rowsFromServices(services: BillableService[] | null): Row[] {
  if (!services?.length) return [emptyRow()];

  return services.map((s) => ({
    key: keySeq++,
    description: s.name,
    quantity: s.quantity,
    unit_price: s.unit_price ?? '0',
    ...(s.medical_service_id ? { medical_service_id: s.medical_service_id } : {}),
  }));
}

export function NewInvoiceModal({
  onClose,
  onCreated,
  initialPatient = null,
  initialServices = null,
  appointmentId = null,
  attendingDoctorId = null,
  attendingDoctorName = null,
}: {
  onClose: () => void;
  onCreated: (invoice: Invoice) => void;
  /** Preselect a patient (Seguros, or the caja "Por cobrar" flow). */
  initialPatient?: BillingPatient | null;
  /**
   * Services the attending doctor declared as performed, to open the invoice
   * already filled in instead of rebuilding it from memory.
   *
   * Always editable: the invoice is signed by whoever charges, not by whoever
   * attended, and a service declared without a price (clinic doctors cannot set
   * one) arrives at 0 for the cash desk to fill in.
   */
  initialServices?: BillableService[] | null;
  /** When charging a completed appointment: links the invoice and attributes the doctor. */
  appointmentId?: number | null;
  attendingDoctorId?: number | null;
  attendingDoctorName?: string | null;
}) {
  // Cashier (no patients.view) uses the billing-scoped picker; clinicians keep
  // the full clinical picker.
  const { can, isClinic, workspace } = useWorkspaceCapabilities();
  const isBilling = !can('patients.view');
  // Without e-invoicing the PDF comes out stamped "DOCUMENTO INTERNO"
  // (invoice.blade.php). Saying so here just moves that truth from the printout
  // to the moment of issuing, which is when it can still be acted on.
  const withoutSri = workspace != null && !workspace.sri_enabled;

  const [patient, setPatient] = useState<BillingPatient | null>(initialPatient);
  const [rows, setRows] = useState<Row[]>(() => rowsFromServices(initialServices));

  // Attending doctor + their share for THIS attention: either a percentage or a
  // fixed amount ("cobramos 25, al médico 19"). In a clinic the cashier picks the
  // doctor and the split (defaulted from the doctor's agreed %); preselected from
  // the completed appointment when charging one.
  const [doctorId, setDoctorId] = useState<number | null>(attendingDoctorId);
  const [commissionMode, setCommissionMode] = useState<'pct' | 'amount'>('pct');
  const [commissionPct, setCommissionPct] = useState('');
  const [commissionAmount, setCommissionAmount] = useState('');
  const commissionTouched = useRef(false);

  const { data: services } = useQuery({ queryKey: ['medical-services'], queryFn: () => fetchMedicalServices() });

  const { data: doctors } = useQuery({
    queryKey: ['billing-doctors'],
    queryFn: fetchBillingDoctors,
    enabled: isClinic && can('billing.manage'),
  });

  // Preload the % from the selected doctor's default share (until the user edits it).
  useEffect(() => {
    if (commissionTouched.current || !doctors) return;
    const doc = doctors.find((d) => d.id === doctorId);
    setCommissionPct(doc?.commission_pct != null ? String(Number(doc.commission_pct)) : '');
  }, [doctors, doctorId]);

  const selectDoctor = (id: number | null) => {
    commissionTouched.current = false;
    setDoctorId(id);
  };

  const mutation = useMutation({
    mutationFn: (payload: InvoiceInput) => createInvoice(payload),
    onSuccess: onCreated,
  });

  const total = rows.reduce((sum, r) => {
    const base = Number(r.quantity || 0) * Number(r.unit_price || 0);
    const disc = base * (Number(r.discount_percentage || 0) / 100);
    return sum + (base - disc);
  }, 0);

  const update = (key: number, patch: Partial<Row>) =>
    setRows((rs) => rs.map((r) => (r.key === key ? { ...r, ...patch } : r)));

  const addFromService = (id: string) => {
    const svc = services?.find((s) => s.id === Number(id));
    if (!svc) return;
    setRows((rs) => [
      ...rs.filter((r) => r.description || r.unit_price !== '0'),
      { key: keySeq++, description: svc.name, quantity: '1', unit_price: svc.base_price, medical_service_id: svc.id },
    ]);
  };

  const submit = () => {
    if (!patient) return;
    const items = rows
      .filter((r) => r.description.trim() && Number(r.unit_price) >= 0)
      .map(({ key: _key, ...item }) => item);
    if (items.length === 0) return;
    mutation.mutate({
      patient_id: patient.id,
      appointment_id: appointmentId ?? undefined,
      attending_doctor_id: doctorId ?? undefined,
      // Percentage and fixed amount are mutually exclusive (server rejects both).
      attending_doctor_commission_pct:
        doctorId != null && commissionMode === 'pct' && commissionPct.trim() !== ''
          ? commissionPct.trim()
          : undefined,
      attending_doctor_commission_amount:
        doctorId != null && commissionMode === 'amount' && commissionAmount.trim() !== ''
          ? commissionAmount.trim()
          : undefined,
      issued_at: ecuadorDateInputValue(),
      items,
    });
  };

  const error = mutation.isError
    ? (isAxiosError(mutation.error) && (mutation.error.response?.data as { message?: string })?.message) ||
      'No se pudo crear la factura.'
    : null;

  return (
    <Modal
      title="Nueva factura"
      size="lg"
      onClose={onClose}
      footer={
        <>
          <button className="btn" onClick={onClose}>Cancelar</button>
          <button className="btn primary" disabled={!patient || mutation.isPending} onClick={submit}>
            {mutation.isPending ? 'Emitiendo…' : `Emitir · ${formatMoney(total.toFixed(2))}`}
          </button>
        </>
      }
    >
      <div className="stack">
        {isBilling ? (
          <BillingPatientPicker selected={patient} onSelect={setPatient} />
        ) : (
          <PatientPicker selected={patient as Patient | null} onSelect={(p) => setPatient(p)} />
        )}

        {isClinic ? (
          <div className="form-grid" style={{ gridTemplateColumns: '2fr 1fr', alignItems: 'end' }}>
            <div>
              <label className="field-label">Médico atendiente</label>
              <select
                className="field"
                value={doctorId ?? ''}
                onChange={(e) => selectDoctor(e.target.value ? Number(e.target.value) : null)}
              >
                <option value="">Sin asignar</option>
                {doctors?.map((d) => (
                  <option key={d.id} value={d.id}>{d.name}</option>
                ))}
                {/* Keep the preselected doctor visible even if the list hasn't loaded. */}
                {doctorId != null && !doctors?.some((d) => d.id === doctorId) && attendingDoctorName && (
                  <option value={doctorId}>{attendingDoctorName}</option>
                )}
              </select>
            </div>
            <div>
              <label className="field-label">
                Comisión médico
                <span className="muted" style={{ fontWeight: 400 }}>
                  {commissionMode === 'amount' ? ' (monto fijo)' : ' (% del cobro)'}
                </span>
              </label>
              <div className="row" style={{ gap: 6 }}>
                <select
                  className="field"
                  style={{ width: 62, padding: '6px 6px' }}
                  aria-label="Tipo de comisión"
                  disabled={doctorId == null}
                  value={commissionMode}
                  onChange={(e) => {
                    commissionTouched.current = true;
                    setCommissionMode(e.target.value as 'pct' | 'amount');
                  }}
                >
                  <option value="pct">%</option>
                  <option value="amount">$</option>
                </select>
                <input
                  className="field mono"
                  inputMode="decimal"
                  placeholder="0"
                  style={{ flex: 1, minWidth: 0 }}
                  disabled={doctorId == null}
                  value={commissionMode === 'amount' ? commissionAmount : commissionPct}
                  onChange={(e) => {
                    commissionTouched.current = true;
                    if (commissionMode === 'amount') setCommissionAmount(e.target.value);
                    else setCommissionPct(e.target.value);
                  }}
                />
              </div>
            </div>
          </div>
        ) : (
          attendingDoctorName && (
            <p className="muted" style={{ fontSize: 12.5 }}>
              <Icon name="user" size={13} /> Médico atendiente: <b>{attendingDoctorName}</b>
            </p>
          )
        )}

        {services && services.length > 0 && (
          <div>
            <label className="field-label">Agregar del arancel</label>
            <select className="field" value="" onChange={(e) => addFromService(e.target.value)}>
              <option value="">Selecciona un servicio…</option>
              {services.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name} — {formatMoney(s.base_price)}
                </option>
              ))}
            </select>
          </div>
        )}

        <div className="stack" style={{ gap: 8 }}>
          {rows.map((r) => (
            <div key={r.key} className="form-grid" style={{ gridTemplateColumns: '2.8fr 0.8fr 1fr auto', alignItems: 'end' }}>
              <div>
                <label className="field-label">Descripción</label>
                <input className="field" value={r.description} onChange={(e) => update(r.key, { description: e.target.value })} />
              </div>
              <div>
                <label className="field-label">Cant.</label>
                <input className="field mono" inputMode="decimal" value={r.quantity} onChange={(e) => update(r.key, { quantity: e.target.value })} />
              </div>
              <div>
                <label className="field-label">P. Unit</label>
                <input className="field mono" inputMode="decimal" value={r.unit_price} onChange={(e) => update(r.key, { unit_price: e.target.value })} />
              </div>
              <button className="icon-btn" onClick={() => setRows((rs) => (rs.length > 1 ? rs.filter((x) => x.key !== r.key) : rs))} aria-label="Quitar ítem">
                <Icon name="trash" size={16} />
              </button>
            </div>
          ))}
        </div>

        <button className="btn sm" style={{ alignSelf: 'flex-start' }} onClick={() => setRows((rs) => [...rs, emptyRow()])}>
          <Icon name="plus" size={14} /> Añadir ítem
        </button>

        {withoutSri && (
          <div className="alert amber">
            <span className="ai"><Icon name="alert" size={16} /></span>
            <div>
              <b>Esta factura no tendrá validez fiscal</b>
              <p>
                Se emitirá como documento interno. Para que el SRI la autorice, activa la facturación
                electrónica en Ajustes.
              </p>
              {can('settings.manage') && (
                <Link className="btn sm alert-cta" to="/ajustes?pane=fiscal" onClick={onClose}>
                  Activar facturación electrónica
                </Link>
              )}
            </div>
          </div>
        )}

        {error && <p className="field-error">{error}</p>}
      </div>
    </Modal>
  );
}
