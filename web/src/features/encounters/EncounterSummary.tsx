import { Icon } from '@/components/Icon';
import { formatDate } from '@/i18n/es';
import type { RecordServiceInput } from '@/api/types';
import type { DiagnosisRow } from '@/features/patients/workspace/Cie10DiagnosisPicker';
import type { PrescriptionRow } from '@/features/patients/workspace/MedicationPicker';

interface EncounterSummaryProps {
  diagnoses: DiagnosisRow[];
  diagnosis: string;
  prescriptions: PrescriptionRow[];
  treatment: string;
  followUpDate: string;
  services: RecordServiceInput[];
  printingRx: boolean;
  busy: boolean;
  onPrintRx: () => void;
  onTerminate: () => void;
  onNew: () => void;
  /** Present only once the atención exists on the server. */
  onVoid?: () => void;
}

/**
 * The atención at a glance: what will go on the receta and into the history,
 * live, without switching tabs — the "vista previa" of design/atencion-orpheus-v3.
 * Each line is read straight from the form; nothing here is edited.
 */
export function EncounterSummary({
  diagnoses,
  diagnosis,
  prescriptions,
  treatment,
  followUpDate,
  services,
  printingRx,
  busy,
  onPrintRx,
  onTerminate,
  onNew,
  onVoid,
}: EncounterSummaryProps) {
  const drugs = prescriptions.filter((p) => p.medication_name.trim());
  const performed = services.filter((s) => s.name.trim());

  return (
    <aside className="enc-summary" aria-label="Resumen de la atención">
      <h4>Resumen de la atención</h4>

      <section className="enc-summary-block" aria-label="Diagnóstico">
        <h5>Diagnóstico</h5>
        {diagnoses.length === 0 && !diagnosis.trim() ? (
          <p className="enc-summary-empty">Sin diagnóstico todavía.</p>
        ) : (
          <ul>
            {diagnoses.map((d) => (
              <li key={d.cie10_code}>
                <span className="mono enc-summary-code">{d.cie10_code}</span> {d.cie10_description}
              </li>
            ))}
            {diagnosis.trim() && <li className="enc-summary-note">{diagnosis.trim()}</li>}
          </ul>
        )}
      </section>

      <section className="enc-summary-block" aria-label="Receta">
        <h5>Receta</h5>
        {drugs.length === 0 ? (
          <p className="enc-summary-empty">Sin medicamentos.</p>
        ) : (
          <ul>
            {drugs.map((p, i) => (
              <li key={`${p.medication_name}-${i}`}>
                {p.medication_name}
                {p.concentration && ` ${p.concentration}`}
                {p.quantity && <span className="enc-summary-qty"> × {p.quantity}</span>}
              </li>
            ))}
          </ul>
        )}
        <button
          className="btn sm enc-summary-print"
          type="button"
          disabled={drugs.length === 0 || printingRx || busy}
          onClick={onPrintRx}
        >
          <Icon name="download" size={14} /> {printingRx ? 'Generando…' : 'Imprimir receta'}
        </button>
      </section>

      {treatment.trim() && (
        <section className="enc-summary-block" aria-label="Plan">
          <h5>Plan</h5>
          <p className="enc-summary-text">{treatment.trim()}</p>
        </section>
      )}

      {performed.length > 0 && (
        <section className="enc-summary-block" aria-label="Servicios">
          <h5>Servicios</h5>
          <ul>
            {performed.map((s, i) => <li key={`${s.name}-${i}`}>{s.name}</li>)}
          </ul>
        </section>
      )}

      <section className="enc-summary-block" aria-label="Próximo control">
        <h5>Próximo control</h5>
        <p className={followUpDate ? 'enc-summary-text' : 'enc-summary-empty'}>
          {followUpDate ? formatDate(followUpDate) : 'Sin control indicado.'}
        </p>
      </section>

      <div className="enc-summary-actions">
        <button className="btn primary enc-summary-finish" type="button" disabled={busy} onClick={onTerminate}>
          <Icon name="check" size={15} /> Terminar atención
        </button>
        <button className="btn ghost sm" type="button" onClick={onNew}>
          <Icon name="plus" size={14} /> Empezar otra atención
        </button>
        {onVoid && (
          <button className="btn ghost sm enc-summary-void" type="button" onClick={onVoid}>
            <Icon name="trash" size={14} /> Anular atención
          </button>
        )}
      </div>
    </aside>
  );
}
