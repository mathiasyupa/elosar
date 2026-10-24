import { useEffect, useState } from 'react';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { fetchPatient, fetchPatients } from '@/api/patients';
import { useAuthStore } from '@/store/auth';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { Icon } from '@/components/Icon';
import { PatientWorkspaceLink } from '@/components/PatientWorkspaceLink';
import { avatarColor, initials } from '@/lib/theme';
import { formatDate, t } from '@/i18n/es';
import type { Patient } from '@/api/types';

function InsuranceDetail({ patient }: { patient: Patient }) {
  const { can } = useWorkspaceCapabilities();
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const navigate = useNavigate();
  const { data } = useQuery({
    queryKey: ['patient', activeWorkspaceId, patient.id],
    queryFn: () => fetchPatient(patient.id),
  });
  const p = data ?? patient;

  return (
    <div className="preview-card">
      <div className="pp-head">
        <span className="avatar pp-avatar" style={{ background: avatarColor(p.id) }}>
          {initials(p.name)}
        </span>
        <div>
          <b>{p.name}</b>
          <span className="muted pp-id"><span className="mono">CI {p.id_number ?? '—'}</span></span>
        </div>
      </div>

      <div className="insurance-hero">
        <Icon name="shield" size={18} />
        <div>
          <b>{p.insurance_provider ?? 'Sin aseguradora'}</b>
          <span>Póliza {p.insurance_policy_number ?? '—'}</span>
        </div>
      </div>

      <dl className="pp-stats">
        <div><dt>Teléfono</dt><dd>{p.phone ?? '—'}</dd></div>
        <div><dt>Ciudad</dt><dd>{p.city ?? '—'}</dd></div>
        <div><dt>Correo</dt><dd style={{ fontSize: 12, fontWeight: 600 }}>{p.email ?? '—'}</dd></div>
        <div><dt>Última visita</dt><dd>{p.last_visit_at ? formatDate(p.last_visit_at) : '—'}</dd></div>
        <div className="full">
          <dt>Contacto de emergencia</dt>
          <dd>{[p.emergency_contact_name, p.emergency_contact_phone].filter(Boolean).join(' · ') || '—'}</dd>
        </div>
      </dl>

      <div className="stack" style={{ gap: 8 }}>
        <PatientWorkspaceLink className="btn primary" style={{ width: '100%' }} patientId={p.id}>
          <Icon name="user" size={15} /> Abrir expediente
        </PatientWorkspaceLink>
        {can('billing.manage') && (
          <button
            className="btn"
            style={{ width: '100%' }}
            onClick={() => navigate('/facturas', { state: { newInvoiceFor: p } })}
          >
            <Icon name="invoice" size={15} /> Nueva factura
          </button>
        )}
      </div>
    </div>
  );
}

export function InsurancePage() {
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const [search, setSearch] = useState('');
  const [selected, setSelected] = useState<Patient | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['patients-insurance', activeWorkspaceId, search],
    queryFn: () => fetchPatients(search, 1, undefined, { hasInsurance: true }),
    placeholderData: keepPreviousData,
  });

  const insured = data?.data ?? [];

  useEffect(() => {
    if (!selected && insured.length) setSelected(insured[0]!);
  }, [insured, selected]);

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Seguros</h1>
          <p>{data?.meta.total != null ? `${data.meta.total} paciente(s) con cobertura` : 'Pacientes con cobertura de seguro médico'}</p>
        </div>
      </div>

      <div className="split-view">
        <Card padded={false}>
          <div className="card-head">
            <div className="search" style={{ maxWidth: 360, flex: 1 }}>
              <Icon name="search" />
              <input placeholder="Buscar asegurado…" value={search} onChange={(e) => setSearch(e.target.value)} />
            </div>
          </div>

          {isLoading && <div className="card-pad"><Spinner label={t.common.loading} /></div>}
          {!isLoading && insured.length === 0 && (
            <div className="card-pad">
              <EmptyState
                icon="shield"
                message="No hay pacientes con seguro registrado."
                hint="La aseguradora y la póliza se cargan en la ficha del paciente."
                action={
                  <Link className="btn sm primary" to="/pacientes">
                    Ir a Pacientes
                  </Link>
                }
              />
            </div>
          )}

          {insured.length > 0 && (
            <table className="tbl tbl-cards">
              <thead>
                <tr><th>Paciente</th><th>Aseguradora</th><th>Póliza</th><th /></tr>
              </thead>
              <tbody>
                {insured.map((p) => (
                  <tr
                    key={p.id}
                    className={`row-link ${selected?.id === p.id ? 'selected' : ''}`.trim()}
                    onClick={(e) => {
                      if ((e.target as HTMLElement).closest('button, a')) return;
                      setSelected(p);
                    }}
                  >
                    <td data-label="Paciente">
                      <div className="who">
                        <span className="avatar-sm" style={{ background: avatarColor(p.id) }}>{initials(p.name)}</span>
                        <PatientWorkspaceLink patientId={p.id}>
                          <b>{p.name}</b>
                        </PatientWorkspaceLink>
                      </div>
                    </td>
                    <td data-label="Aseguradora"><span className="badge violet">{p.insurance_provider}</span></td>
                    <td className="mono" data-label="Póliza">{p.insurance_policy_number ?? '—'}</td>
                    <td className="num muted" data-label=""><Icon name="arrow" size={15} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>

        <aside className="split-aside">
          {selected ? (
            <InsuranceDetail patient={selected} />
          ) : (
            <Card><p className="muted" style={{ fontSize: 13 }}>Selecciona un paciente para ver su seguro.</p></Card>
          )}
        </aside>
      </div>
    </>
  );
}
