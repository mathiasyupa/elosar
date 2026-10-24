import { useLayoutEffect, useRef } from 'react';
import { Link, NavLink, useLocation } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Icon } from '@/components/Icon';
import { PregnancyChip } from '@/features/obstetrics/PregnancyChip';
import { PatientAlertChip } from '@/features/patients/PatientAlerts';
import { fetchPatientRecords } from '@/api/patients';
import { useClinicalModules, useWorkspaceCapabilities } from '@/lib/capabilities';
import { avatarColor, initials } from '@/lib/theme';
import { formatDate } from '@/i18n/es';
import { GENDER_LABELS, labelFor } from '@/i18n/labels';
import { useAuthStore } from '@/store/auth';
import { confirmLeaveEncounter } from '@/store/encounterGuard';
import type { ClinicalModule, Patient, Permission } from '@/api/types';

interface SectionEntry {
  to: string;
  icon: string;
  label: string;
  /** Required permission; omitted = always visible (demographics). */
  perm?: Permission;
  /** Server-derived clinical module required to expose the section. */
  module?: ClinicalModule;
}

/** Expediente sections. "Atención" is not listed here — it's rendered after
 *  Resumen in the patient section nav, because whether it reads "Iniciar atención"
 *  or "Atención en curso" depends on data this list doesn't carry. */
const SECTIONS: SectionEntry[] = [
  { to: 'resumen', icon: 'user', label: 'Resumen' },
  { to: 'historial', icon: 'file', label: 'Historial', perm: 'records.view' },
  { to: 'recetas', icon: 'pill', label: 'Recetas', perm: 'records.view' },
  { to: 'ordenes', icon: 'flask', label: 'Exámenes', perm: 'records.view' },
  // `users` y no un icono propio: `Icon` pinta un SVG vacío para un nombre que no
  // existe, sin avisar, así que un nombre inventado deja un hueco en el menú.
  { to: 'gineco', icon: 'users', label: 'Gíneco-obstétrico', perm: 'records.view', module: 'gyneco_obstetrics' },
  { to: 'vacunas', icon: 'syringe', label: 'Vacunas', perm: 'vitals.create' },
  { to: 'certificados', icon: 'shield', label: 'Certificados', perm: 'records.view' },
  { to: 'documentos', icon: 'folder', label: 'Documentos', perm: 'records.view' },
];

function Fact({ icon, children }: { icon: string; children: React.ReactNode }) {
  return (
    <div className="pw-patient-fact">
      <Icon name={icon} size={14} />
      <span className="ellipsis">{children}</span>
    </div>
  );
}

/** The patient file header: the way back to Pacientes, identity, clinical
 *  alerts and the permission-gated section tabs, on every patient section.
 *  It replaces the app bar here — inside a patient file the doctor only
 *  needs the patient, not search or account controls. */
export function PatientRail({ patient }: { patient: Patient }) {
  const { pathname } = useLocation();
  const navRef = useRef<HTMLElement>(null);
  const { can } = useWorkspaceCapabilities();
  const clinicalModules = useClinicalModules();
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);

  const { data: records } = useQuery({
    queryKey: ['patient-records', activeWorkspaceId, patient.id],
    queryFn: () => fetchPatientRecords(patient.id),
    enabled: can('records.manage'),
  });
  const hasOpenRecord = (records?.data ?? []).some((r) => r.status !== 'completed');

  const sections = SECTIONS.filter(
    (section) =>
      (!section.perm || can(section.perm)) &&
      (!section.module || clinicalModules.has(section.module)),
  );
  const renderSection = (section: SectionEntry) => (
    <NavLink
      key={section.to}
      to={section.to}
      className={({ isActive }) => `pw-patient-tab ${isActive ? 'active' : ''}`.trim()}
    >
      <Icon name={section.icon} size={16} />
      <span>{section.label}</span>
    </NavLink>
  );
  const allergies = patient.allergies ?? [];

  const metaRest = [
    labelFor(GENDER_LABELS, patient.gender),
    patient.blood_type,
  ]
    .filter(Boolean)
    .join(' · ');

  // AppLayout remounts the patient workspace on route changes. On narrow
  // screens, keep the selected destination visible in the horizontal strip
  // without scrolling the clinical page vertically.
  useLayoutEffect(() => {
    const nav = navRef.current;
    const active = nav?.querySelector<HTMLElement>('.pw-patient-tab.active');
    if (!nav || !active || nav.clientWidth <= 0) return;

    const navLeft = nav.getBoundingClientRect().left;
    const activeRect = active.getBoundingClientRect();
    const left = activeRect.left - navLeft + nav.scrollLeft;
    const right = activeRect.right - navLeft + nav.scrollLeft;
    if (left < nav.scrollLeft) nav.scrollLeft = Math.max(0, left);
    else if (right > nav.scrollLeft + nav.clientWidth - 20) {
      nav.scrollLeft = right - nav.clientWidth + 20;
    }
  }, [pathname]);

  return (
    <section className="pw-patient-header" aria-label="Paciente y secciones del expediente">
      <div className="pw-patient-identity">
        <Link
          to="/pacientes"
          className="icon-btn pw-back"
          aria-label="Volver a Pacientes"
          title="Volver a Pacientes"
          // Leaving the patient is the one exit that unmounts the atención.
          onClick={(e) => {
            if (!confirmLeaveEncounter()) e.preventDefault();
          }}
        >
          <Icon name="chevron" size={18} />
        </Link>
        <div
          className="avatar pw-patient-avatar"
          style={{ background: avatarColor(patient.id) }}
          aria-hidden="true"
        >
          {initials(patient.name)}
        </div>
        <div className="pw-patient-main">
          <h1>{patient.name}</h1>
          {(patient.id_number || patient.age != null || metaRest) && (
            <span className="pr-meta">
              {patient.id_number && <span className="mono">CI {patient.id_number}</span>}
              {patient.age != null && <>{patient.id_number ? ' · ' : ''}<span className="mono">{patient.age} años</span></>}
              {metaRest && `${patient.id_number || patient.age != null ? ' · ' : ''}${metaRest}`}
            </span>
          )}
          <div className="pw-patient-facts">
            {patient.phone && <Fact icon="phone"><span className="mono">{patient.phone}</span></Fact>}
            {patient.email && <Fact icon="mail">{patient.email}</Fact>}
            {/* Address when there is one, city for records that predate it. */}
            {(patient.address || patient.city) && (
              <Fact icon="pin">{patient.address || patient.city}</Fact>
            )}
            {patient.insurance_provider && (
              <Fact icon="card">
                {patient.insurance_provider}
                {patient.insurance_policy_number ? ` · ${patient.insurance_policy_number}` : ''}
              </Fact>
            )}
            <Fact icon="clock">
              Última visita: {patient.last_visit_at ? <span className="mono">{formatDate(patient.last_visit_at)}</span> : 'sin registro'}
            </Fact>
          </div>
        </div>
        <div className="pw-patient-alerts" aria-label="Avisos del paciente">
          {(patient.alerts ?? []).map((a) => (
            <PatientAlertChip key={a.id} alert={a} />
          ))}
          {clinicalModules.has('gyneco_obstetrics') && patient.active_pregnancy && (
            <PregnancyChip pregnancy={patient.active_pregnancy} />
          )}
          {allergies.length === 0 ? (
            <span className="pr-noalergy">
              <Icon name="check" size={12} /> Sin alergias registradas
            </span>
          ) : (
            allergies.map((a) => (
              <span key={a.id} className="allergy-chip" title={a.notes ?? undefined}>
                <Icon name="shield" size={12} />
                {a.allergen_name ?? a.allergen_type ?? 'Alergia'}
                {a.severity ? ` · ${a.severity}` : ''}
              </span>
            ))
          )}
        </div>
      </div>

      <nav ref={navRef} className="pw-patient-nav" aria-label="Secciones del expediente">
          {sections.slice(0, 1).map(renderSection)}
          {can('records.manage') && (
            // A section like the others, client-side: PatientWorkspaceLayout
            // keeps the atención mounted, so going to Recetas and back keeps
            // what was typed. A `target` here would force a full page load and
            // throw it away.
            <NavLink
              to={`/pacientes/${patient.id}/atencion`}
              className={({ isActive }) => `pw-patient-tab ${isActive ? 'active' : ''} ${hasOpenRecord ? 'live' : ''}`.trim()}
            >
              <Icon name="steth" size={16} />
              <span>{hasOpenRecord ? 'Atención en curso' : 'Iniciar atención'}</span>
            </NavLink>
          )}
          {sections.slice(1).map(renderSection)}
      </nav>
    </section>
  );
}
