import { patientTabName } from '@/lib/openPatient';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { fetchPatient } from '@/api/patients';
import type { Patient } from '@/api/types';
import { Icon } from '@/components/Icon';
import { PatientWorkspaceLink } from '@/components/PatientWorkspaceLink';
import { QueryError } from '@/components/ui/QueryError';
import { Spinner } from '@/components/ui/Spinner';
import { formatDate } from '@/i18n/es';
import { initials } from '@/lib/theme';
import { useAuthStore } from '@/store/auth';

const QUICK_TABS = [
  { to: 'resumen', icon: 'user', label: 'Resumen', capability: null },
  { to: 'historial', icon: 'file', label: 'Historial', capability: 'view' },
  { to: 'consulta', icon: 'steth', label: 'Consulta', capability: 'manage' },
  { to: 'recetas', icon: 'pill', label: 'Recetas', capability: 'view' },
] as const;

interface PatientPreviewPanelProps {
  patient: Patient;
  canViewRecords: boolean;
  canManageRecords: boolean;
}

function patientAvatarTone(patientId: number): string {
  return `patient-avatar-tone-${Math.abs(patientId) % 5}`;
}

function canUseShortcut(
  capability: (typeof QUICK_TABS)[number]['capability'],
  canViewRecords: boolean,
  canManageRecords: boolean,
): boolean {
  if (capability === 'view') return canViewRecords;
  if (capability === 'manage') return canManageRecords;
  return true;
}

function ConfirmedPatientDetails({ patient }: { patient: Patient }) {
  const allergies = patient.allergies;
  const insurance =
    patient.insurance_provider === undefined
      ? 'No disponible'
      : patient.insurance_provider ?? 'Particular';
  const familyBackgroundCount = Array.isArray(patient.family_background)
    ? patient.family_background.length
    : '—';

  return (
    <>
      {Array.isArray(allergies) ? (
        allergies.length > 0 ? (
          <div className="alert red patient-allergies">
            <span className="ai">
              <Icon name="alert" size={15} />
            </span>
            <div>
              <b>Alergias</b>
              <p>
                {allergies
                  .map((allergy) => allergy.allergen_name ?? allergy.allergen_type)
                  .filter(Boolean)
                  .join(' · ')}
              </p>
            </div>
          </div>
        ) : (
          <span className="badge green plain patient-allergies-clear">Sin alergias</span>
        )
      ) : (
        <p className="patient-detail-unknown">Información de alergias no disponible.</p>
      )}

      <dl className="pp-stats">
        <div>
          <dt>Última visita</dt>
          <dd>{patient.last_visit_at ? formatDate(patient.last_visit_at) : '—'}</dd>
        </div>
        <div>
          <dt>Antec. familiares</dt>
          <dd>{familyBackgroundCount}</dd>
        </div>
        <div>
          <dt>Sangre</dt>
          <dd>{patient.blood_type ?? '—'}</dd>
        </div>
        <div>
          <dt>Seguro</dt>
          <dd>{insurance}</dd>
        </div>
      </dl>
    </>
  );
}

export function PatientPreviewPanel({
  patient,
  canViewRecords,
  canManageRecords,
}: PatientPreviewPanelProps) {
  const activeWorkspaceId = useAuthStore((state) => state.activeWorkspaceId);
  const { data, isError, isPaused, error, refetch, isFetching } = useQuery({
    queryKey: ['patient', activeWorkspaceId, patient.id],
    queryFn: () => fetchPatient(patient.id),
  });
  const identity = data ?? patient;

  return (
    <div className="patient-preview">
      <div className="pp-head">
        <span
          className={`avatar pp-avatar patient-avatar ${patientAvatarTone(identity.id)}`}
          aria-hidden="true"
        >
          {initials(identity.name)}
        </span>
        <div>
          <b>{identity.name}</b>
          <span className="muted pp-id">
            <span className="mono">CI {identity.id_number ?? '—'}</span>
            {identity.age != null ? ` · ${identity.age} años` : ''}
          </span>
        </div>
      </div>

      <div className="patient-preview-detail">
        {isError || isPaused ? (
          <QueryError
            error={error}
            area="el detalle del paciente"
            onRetry={() => void refetch()}
            retrying={isFetching}
            paused={isPaused}
            variant="inline"
          />
        ) : data ? (
          <ConfirmedPatientDetails patient={data} />
        ) : (
          <Spinner label="Cargando detalle del paciente…" />
        )}
      </div>

      <div className="pp-actions">
        {QUICK_TABS.filter((tab) =>
          canUseShortcut(tab.capability, canViewRecords, canManageRecords),
        ).map((tab) =>
          tab.to === 'consulta' ? (
            <Link key={tab.to} className="pp-action" to={`/pacientes/${identity.id}/${tab.to}`} target={patientTabName(identity.id)}>
              <Icon name={tab.icon} size={15} />
              {tab.label}
            </Link>
          ) : (
            <PatientWorkspaceLink
              key={tab.to}
              className="pp-action"
              patientId={identity.id}
              section={tab.to}
            >
              <Icon name={tab.icon} size={15} />
              {tab.label}
            </PatientWorkspaceLink>
          ),
        )}
      </div>
      <PatientWorkspaceLink
        className="btn primary patient-preview-open"
        patientId={identity.id}
      >
        Abrir expediente
      </PatientWorkspaceLink>
    </div>
  );
}
