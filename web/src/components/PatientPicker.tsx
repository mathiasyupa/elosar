import { patientTabName } from '@/lib/openPatient';
import { useState } from 'react';
import { useInfiniteQuery, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { fetchPatient, fetchPatients, PATIENT_PICKER_PAGE_SIZE } from '@/api/patients';
import { useAuthStore } from '@/store/auth';
import { NewPatientModal } from '@/features/patients/NewPatientModal';
import { Card } from '@/components/ui/Card';
import { Icon } from '@/components/Icon';
import { avatarColor, initials } from '@/lib/theme';
import { formatDate } from '@/i18n/es';
import type { Patient } from '@/api/types';

interface Props {
  selected: Patient | null;
  onSelect: (patient: Patient | null) => void;
  label?: string;
  /** Show "Nuevo paciente" when no patient is selected yet. Default true. */
  allowCreate?: boolean;
}

function PatientRow({ patient, onClick }: { patient: Patient; onClick: () => void }) {
  return (
    <button className="ws-option" onClick={onClick} type="button">
      <span className="avatar-sm" style={{ background: avatarColor(patient.id) }}>
        {initials(patient.name)}
      </span>
      <span className="wo-meta" style={{ flex: 1 }}>
        <b>{patient.name}</b>
        <span>
          CI {patient.id_number ?? '—'}
          {patient.age != null ? ` · ${patient.age} años` : ''}
          {patient.last_visit_at ? ` · última visita ${formatDate(patient.last_visit_at)}` : ' · primera visita'}
        </span>
      </span>
    </button>
  );
}

/** Reusable patient search + select with recents, paginated results, optional
 *  new-patient registration, and a mini-profile preview once selected. */
export function PatientPicker({ selected, onSelect, label = 'Paciente', allowCreate = true }: Props) {
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const qc = useQueryClient();
  const [search, setSearch] = useState('');
  const [showNew, setShowNew] = useState(false);
  const term = search.trim();
  const isSearching = term.length >= 2;

  const { data, fetchNextPage, hasNextPage, isFetchingNextPage, isLoading } = useInfiniteQuery({
    queryKey: ['patient-picker', activeWorkspaceId, term],
    queryFn: ({ pageParam }) =>
      fetchPatients(term, pageParam, isSearching ? undefined : 'recent', {
        perPage: PATIENT_PICKER_PAGE_SIZE,
      }),
    initialPageParam: 1,
    getNextPageParam: (last) =>
      last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined,
    enabled: !selected,
  });

  // Mini-profile of the selected patient (allergies + last visit).
  const { data: detail } = useQuery({
    queryKey: ['patient', selected?.id],
    queryFn: () => fetchPatient(selected!.id),
    enabled: !!selected,
  });

  const list = data?.pages.flatMap((page) => page.data) ?? [];
  const total = data?.pages[0]?.meta.total;

  const onPatientCreated = (patient: Patient) => {
    setShowNew(false);
    void qc.invalidateQueries({ queryKey: ['patient-picker'] });
    void qc.invalidateQueries({ queryKey: ['patients'] });
    onSelect(patient);
  };

  if (selected) {
    const allergies = detail?.allergies ?? [];
    return (
      <Card>
        <div className="row between" style={{ marginBottom: allergies.length || detail ? 12 : 0 }}>
          <div className="who">
            <span className="avatar-sm" style={{ background: avatarColor(selected.id) }}>
              {initials(selected.name)}
            </span>
            <div>
              <b>{selected.name}</b>
              <span>CI {selected.id_number}{selected.age != null ? ` · ${selected.age} años` : ''}</span>
            </div>
          </div>
          <div className="row" style={{ gap: 6 }}>
            <Link className="btn sm" to={`/pacientes/${selected.id}/resumen`} target={patientTabName(selected.id)}>Ver ficha</Link>
            <button className="btn sm" onClick={() => onSelect(null)}>Cambiar</button>
          </div>
        </div>

        {detail && (
          <div className="stack" style={{ gap: 8 }}>
            <div className="row wrap" style={{ gap: 6 }}>
              {allergies.length > 0 ? (
                allergies.map((a) => (
                  <span key={a.id} className="badge red plain" title={a.notes ?? undefined}>
                    <Icon name="shield" size={12} /> {a.allergen_name}
                  </span>
                ))
              ) : (
                <span className="badge green">Sin alergias registradas</span>
              )}
            </div>
            <div className="row wrap muted" style={{ gap: 16, fontSize: 12.5 }}>
              <span>Última visita: {detail.last_visit_at ? formatDate(detail.last_visit_at) : '—'}</span>
              <span>Antecedentes familiares: {detail.family_background?.length ?? 0}</span>
              {detail.blood_type && <span>Tipo de sangre: {detail.blood_type}</span>}
            </div>
          </div>
        )}
      </Card>
    );
  }

  return (
    <>
      <Card>
        {allowCreate && (
          <button
            className="btn primary"
            style={{ width: '100%', marginBottom: 12 }}
            onClick={() => setShowNew(true)}
            type="button"
          >
            <Icon name="plus" size={16} strokeWidth={2.2} /> Nuevo paciente
          </button>
        )}

        <label className="field-label">{label}</label>
        <div className="search">
          <Icon name="search" />
          <input
            placeholder="Buscar por nombre o cédula…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>

        <div className="row between" style={{ margin: '12px 0 4px' }}>
          <span className="field-label" style={{ margin: 0 }}>
            {isSearching ? 'Resultados' : 'Pacientes recientes'}
          </span>
          {total != null && (
            <span className="muted" style={{ fontSize: 12 }}>
              {list.length} de {total}
            </span>
          )}
        </div>

        {isLoading && list.length === 0 ? (
          <p className="muted" style={{ marginTop: 10, fontSize: 13 }}>Cargando pacientes…</p>
        ) : list.length > 0 ? (
          <div className="stack" style={{ gap: 4, marginTop: 4 }}>
            {list.map((p) => (
              <PatientRow key={p.id} patient={p} onClick={() => onSelect(p)} />
            ))}

            {hasNextPage && (
              <button
                className="btn sm"
                style={{ width: '100%', marginTop: 6 }}
                disabled={isFetchingNextPage}
                onClick={() => fetchNextPage()}
                type="button"
              >
                {isFetchingNextPage ? 'Cargando…' : 'Ver más pacientes'}
              </button>
            )}
          </div>
        ) : (
          <div className="stack" style={{ gap: 10, marginTop: 10 }}>
            <p className="muted" style={{ fontSize: 13 }}>
              {isSearching ? 'Sin resultados.' : 'No hay pacientes todavía.'}
            </p>
            {allowCreate && (
              <button className="btn primary" onClick={() => setShowNew(true)} type="button">
                <Icon name="plus" size={16} /> Registrar nuevo paciente
              </button>
            )}
          </div>
        )}
      </Card>

      {showNew && (
        <NewPatientModal onClose={() => setShowNew(false)} onCreated={onPatientCreated} />
      )}
    </>
  );
}
