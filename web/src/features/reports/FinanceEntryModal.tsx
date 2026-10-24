import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { createFinanceEntry, updateFinanceEntry, type FinanceEntryPayload } from '@/api/finance';
import { fetchBillingDoctors } from '@/api/billing';
import type { FinanceEntry, FinanceEntryType } from '@/api/types';
import { Modal } from '@/components/ui/Modal';
import { Icon } from '@/components/Icon';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { ymd } from './PeriodBar';

/** The three kinds of movement, in the words the clinic uses. */
const TYPES: { key: FinanceEntryType; label: string; hint: string; icon: string }[] = [
  { key: 'expense', label: 'Gasto', hint: 'Sale dinero de la clínica', icon: 'card' },
  { key: 'income', label: 'Otro ingreso', hint: 'Entra dinero sin factura', icon: 'money' },
  { key: 'doctor_adjustment', label: 'Descuento a médico', hint: 'Se descuenta de su liquidación', icon: 'user' },
];

/** Category options per type — English values, Spanish labels (schema convention). */
export const CATEGORY_LABELS: Record<string, string> = {
  supplies: 'Insumos médicos',
  utilities: 'Servicios básicos',
  rent: 'Alquiler del local',
  maintenance: 'Mantenimiento',
  equipment: 'Equipamiento',
  payroll: 'Personal de apoyo',
  taxes: 'Impuestos y tasas',
  room_rental: 'Alquiler de consultorio',
  equipment_rental: 'Alquiler de equipos',
  advance: 'Adelanto entregado',
  other: 'Otros',
};

const CATEGORIES: Record<FinanceEntryType, string[]> = {
  expense: ['supplies', 'utilities', 'rent', 'maintenance', 'equipment', 'payroll', 'taxes', 'other'],
  income: ['room_rental', 'equipment_rental', 'other'],
  doctor_adjustment: ['supplies', 'advance', 'other'],
};

/**
 * Registers (or edits) one hand-typed movement. The type drives everything
 * else: an expense and an income are clinic-wide, a deduction always belongs to
 * one doctor and comes off their settlement.
 */
export function FinanceEntryModal({
  entry,
  defaultDate,
  onClose,
  onSaved,
}: {
  /** Editing an existing movement, or null to create one. */
  entry?: FinanceEntry | null;
  /** Date the period is showing, so a new movement lands where the user is looking. */
  defaultDate?: string;
  onClose: () => void;
  onSaved: () => void;
}) {
  const { isClinic } = useWorkspaceCapabilities();

  const [type, setType] = useState<FinanceEntryType>(entry?.type ?? 'expense');
  const [category, setCategory] = useState(entry?.category ?? 'supplies');
  const [description, setDescription] = useState(entry?.description ?? '');
  const [amount, setAmount] = useState(entry?.amount ?? '');
  // A movement is never dated in the future, so a period ending later this month
  // still opens on today.
  const today = ymd(new Date());
  const [entryDate, setEntryDate] = useState(
    entry?.entry_date ?? (defaultDate && defaultDate < today ? defaultDate : today),
  );
  const [doctorId, setDoctorId] = useState<number | null>(entry?.doctor_id ?? null);
  const [receipt, setReceipt] = useState(entry?.receipt_number ?? '');
  const [notes, setNotes] = useState(entry?.notes ?? '');
  const [error, setError] = useState<string | null>(null);

  const { data: doctors } = useQuery({
    queryKey: ['billing-doctors'],
    queryFn: fetchBillingDoctors,
    enabled: isClinic,
  });

  const save = useMutation({
    mutationFn: (payload: FinanceEntryPayload) =>
      entry ? updateFinanceEntry(entry.id, payload) : createFinanceEntry(payload),
    onSuccess: () => {
      onSaved();
      onClose();
    },
    onError: (err) => {
      const message = isAxiosError(err)
        ? (err.response?.data as { message?: string } | undefined)?.message
        : undefined;
      setError(message ?? 'No se pudo guardar el movimiento.');
    },
  });

  const pickType = (next: FinanceEntryType) => {
    setType(next);
    // Keep the category valid for the new type.
    if (!CATEGORIES[next].includes(category)) setCategory(CATEGORIES[next][0]);
  };

  const submit = () => {
    setError(null);

    if (!description.trim()) return setError('Escribe una descripción del movimiento.');
    if (!(Number(amount) > 0)) return setError('El monto debe ser mayor a cero.');
    if (type === 'doctor_adjustment' && !doctorId) return setError('Elige el médico al que se le descuenta.');

    save.mutate({
      type,
      category,
      description: description.trim(),
      amount: String(amount),
      entry_date: entryDate,
      doctor_id: type === 'doctor_adjustment' ? doctorId : null,
      receipt_number: receipt.trim() || null,
      notes: notes.trim() || null,
    });
  };

  return (
    <Modal
      title={entry ? 'Editar movimiento' : 'Registrar movimiento'}
      onClose={onClose}
      footer={
        <>
          <button className="btn ghost" type="button" onClick={onClose}>
            Cancelar
          </button>
          <button className="btn primary" type="button" onClick={submit} disabled={save.isPending}>
            {save.isPending ? 'Guardando…' : 'Guardar'}
          </button>
        </>
      }
    >
      <div className="form-grid">
        <div className="full type-pick">
          {TYPES.map((option) => (
            <button
              key={option.key}
              type="button"
              className={`type-opt ${type === option.key ? 'active' : ''}`}
              onClick={() => pickType(option.key)}
              disabled={option.key === 'doctor_adjustment' && !isClinic}
            >
              <Icon name={option.icon} size={16} />
              <b>{option.label}</b>
              <span className="muted">{option.hint}</span>
            </button>
          ))}
        </div>

        <div>
          <label className="field-label" htmlFor="fe-category">Categoría</label>
          <select
            id="fe-category"
            className="field"
            value={category}
            onChange={(e) => setCategory(e.target.value)}
          >
            {CATEGORIES[type].map((c) => (
              <option key={c} value={c}>
                {CATEGORY_LABELS[c] ?? c}
              </option>
            ))}
          </select>
        </div>

        <div>
          <label className="field-label" htmlFor="fe-date">Fecha</label>
          <input
            id="fe-date"
            className="field"
            type="date"
            value={entryDate}
            max={today}
            onChange={(e) => setEntryDate(e.target.value)}
          />
        </div>

        <div className="full">
          <label className="field-label" htmlFor="fe-description">Descripción</label>
          <input
            id="fe-description"
            className="field"
            value={description}
            placeholder={
              type === 'income'
                ? 'Alquiler de consultorio a Dra. Narváez'
                : type === 'doctor_adjustment'
                ? 'Insumos consumidos en procedimientos'
                : 'Guantes, jeringas y gasas'
            }
            onChange={(e) => setDescription(e.target.value)}
          />
        </div>

        <div>
          <label className="field-label" htmlFor="fe-amount">Monto</label>
          <input
            id="fe-amount"
            className="field mono"
            type="number"
            min="0"
            step="0.01"
            value={amount}
            placeholder="0.00"
            onChange={(e) => setAmount(e.target.value)}
          />
        </div>

        {type === 'doctor_adjustment' ? (
          <div>
            <label className="field-label" htmlFor="fe-doctor">Médico</label>
            <select
              id="fe-doctor"
              className="field"
              value={doctorId ?? ''}
              onChange={(e) => setDoctorId(e.target.value ? Number(e.target.value) : null)}
            >
              <option value="">Selecciona…</option>
              {(doctors ?? []).map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                </option>
              ))}
            </select>
          </div>
        ) : (
          <div>
            <label className="field-label" htmlFor="fe-receipt">N.º de comprobante</label>
            <input
              id="fe-receipt"
              className="field mono"
              value={receipt}
              placeholder="001-002-000123"
              onChange={(e) => setReceipt(e.target.value)}
            />
          </div>
        )}

        <div className="full">
          <label className="field-label" htmlFor="fe-notes">Nota (opcional)</label>
          <textarea
            id="fe-notes"
            className="field"
            rows={2}
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
          />
        </div>

        {type === 'doctor_adjustment' && (
          <p className="full note-line">
            <Icon name="alert" size={14} />
            Este descuento se resta de lo que se le paga al médico en el período y queda en la clínica.
          </p>
        )}

        {error && <p className="full field-error">{error}</p>}
      </div>
    </Modal>
  );
}
