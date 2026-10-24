import type { Patient } from '@/api/types';
import { PatientRowMenu } from './PatientRowMenu';
import { Icon } from '@/components/Icon';
import { PatientWorkspaceLink } from '@/components/PatientWorkspaceLink';
import { formatDate } from '@/i18n/es';
import { initials } from '@/lib/theme';
import { whatsappLink } from '@/lib/whatsapp';

interface PatientDirectoryTableProps {
  patients: Patient[];
  /** The patient shown in the preview panel. Unrelated to the checkboxes. */
  selectedId: number | null;
  onSelect: (patient: Patient) => void;
  /**
   * @deprecated Opening the expediente is now an anchor so the browser can
   * reuse its named workspace tab. Kept temporarily for parent compatibility.
   */
  onOpen?: (patient: Patient) => void;
  /**
   * Multi-selection for bulk actions. Deliberately named apart from `selectedId`:
   * one drives what you are looking at, the other what you are about to act on,
   * and conflating them is how a doctor deletes the wrong row.
   */
  /** Abre el borrado de UN paciente, sin pasar por la selección múltiple. */
  onDelete?: (patient: Patient) => void;
  checkedIds?: Set<number>;
  onToggleCheck?: (id: number) => void;
  onToggleAll?: () => void;
}

function patientAvatarTone(patientId: number): string {
  return `patient-avatar-tone-${Math.abs(patientId) % 5}`;
}

function PatientContactActions({ patient }: { patient: Patient }) {
  const firstName = patient.name.split(/\s+/)[0] ?? patient.name;
  const whatsappHref = whatsappLink(patient.phone, `¡Hola, ${firstName}! `);

  return (
    <div className="patient-contact-actions">
      {whatsappHref ? (
        <a
          className="icon-btn wa-ghost"
          href={whatsappHref}
          target="_blank"
          rel="noopener noreferrer"
          aria-label={`Escribir a ${patient.name} por WhatsApp`}
          title="Escribir por WhatsApp"
        >
          <Icon name="whatsapp" size={16} />
        </a>
      ) : null}
      {patient.email ? (
        <a
          className="icon-btn"
          href={`mailto:${patient.email}`}
          aria-label={`Escribir a ${patient.name} por correo`}
          title="Escribir por correo"
        >
          <Icon name="mail" size={16} />
        </a>
      ) : null}
    </div>
  );
}

export function PatientDirectoryTable({
  patients,
  selectedId,
  onSelect,
  onDelete,
  checkedIds,
  onToggleCheck,
  onToggleAll,
}: PatientDirectoryTableProps) {
  const selectable = Boolean(checkedIds && onToggleCheck && onToggleAll);
  const allChecked = selectable && patients.every((p) => checkedIds!.has(p.id));

  return (
    <table className="tbl tbl-cards patient-directory-table">
      <thead>
        <tr>
          {selectable && (
            <th className="patient-check-cell">
              <input
                type="checkbox"
                checked={allChecked}
                onChange={onToggleAll}
                aria-label={allChecked ? 'Quitar la selección' : 'Seleccionar todos los de esta página'}
              />
            </th>
          )}
          <th className="patient-name-column">Paciente</th>
          <th className="patient-document-column">Cédula</th>
          <th className="patient-age-column">Edad</th>
          <th className="patient-last-visit-column">Última visita</th>
          <th className="patient-actions-column">Acciones</th>
        </tr>
      </thead>
      <tbody>
        {patients.map((patient) => {
          const isSelected = selectedId === patient.id;

          const handleRowClick = (e: React.MouseEvent<HTMLTableRowElement>) => {
            const target = e.target as HTMLElement;
            if (target.closest('button, a, input, select')) {
              return;
            }
            onSelect(patient);
          };

          return (
            <tr
              key={patient.id}
              className={`row-link ${isSelected ? 'selected' : ''}`}
              onClick={handleRowClick}
            >
              {selectable && (
                <td className="patient-check-cell" data-label="Seleccionar">
                  <input
                    type="checkbox"
                    checked={checkedIds!.has(patient.id)}
                    onChange={() => onToggleCheck!(patient.id)}
                    aria-label={`Marcar a ${patient.name}`}
                  />
                </td>
              )}
              <td className="patient-main-cell" data-label="Paciente">
                <button
                  className="patient-select"
                  type="button"
                  aria-label={`Seleccionar a ${patient.name}`}
                  aria-pressed={isSelected}
                  onClick={() => onSelect(patient)}
                >
                  <span
                    className={`avatar-sm patient-avatar ${patientAvatarTone(patient.id)}`}
                    aria-hidden="true"
                  >
                    {initials(patient.name)}
                  </span>
                  <span className="patient-select-copy">
                    <b>{patient.name}</b>
                    <span className="patient-selected-label">
                      <Icon name="check" size={13} />
                      Seleccionado
                    </span>
                  </span>
                </button>
              </td>
              <td className="mono" data-label="Cédula">
                {patient.id_number ?? '—'}
              </td>
              <td data-label="Edad">{patient.age != null ? `${patient.age} años` : '—'}</td>
              <td data-label="Última visita">
                {patient.last_visit_at ? formatDate(patient.last_visit_at) : '—'}
              </td>
              <td className="patient-actions-cell" data-label="Acciones">
                <div className="patient-directory-actions">
                  <PatientContactActions patient={patient} />
                  <PatientWorkspaceLink
                    className="btn sm"
                    patientId={patient.id}
                    aria-label={`Abrir expediente de ${patient.name}`}
                  >
                    Abrir expediente
                  </PatientWorkspaceLink>
                  {onDelete && (
                    <PatientRowMenu patientName={patient.name} onDelete={() => onDelete(patient)} />
                  )}
                </div>
              </td>
            </tr>
          );
        })}
      </tbody>
    </table>
  );
}
