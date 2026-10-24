import { useOpenPatient } from '@/lib/openPatient';
import { useRef, useState } from 'react';
import { useInfiniteQuery, useQueryClient } from '@tanstack/react-query';
import { fetchPatients, PATIENT_PICKER_PAGE_SIZE } from '@/api/patients';
import { useAuthStore } from '@/store/auth';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { useUiStore } from '@/store/ui';
import { Modal } from '@/components/ui/Modal';
import { Icon } from '@/components/Icon';
import { NewPatientModal } from '@/features/patients/NewPatientModal';
import { avatarColor, initials } from '@/lib/theme';
import { formatDate } from '@/i18n/es';
import type { Patient } from '@/api/types';

/** Global "Nueva consulta" launcher. Search a patient (or pick a recent one),
 *  register a new patient on the spot, and jump straight to their consultation tab. */
export function QuickPatientSearch() {
  const open = useUiStore((s) => s.quickSearchOpen);
  const close = useUiStore((s) => s.closeQuickSearch);
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const openPatient = useOpenPatient();
  // Only a doctor opens an atención; anyone else lands on the patient's file.
  const canAttend = useWorkspaceCapabilities().can('records.manage');
  const qc = useQueryClient();
  const [search, setSearch] = useState('');
  const [showNew, setShowNew] = useState(false);
  const searchInputRef = useRef<HTMLInputElement>(null);
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
    enabled: open,
  });

  if (!open) return null;

  const list = data?.pages.flatMap((page) => page.data) ?? [];
  const total = data?.pages[0]?.meta.total;

  const pick = (patient: Patient) => {
    close();
    setSearch('');
    openPatient(patient.id, canAttend ? 'atencion' : 'resumen');
  };

  const onPatientCreated = (patient: Patient) => {
    setShowNew(false);
    void qc.invalidateQueries({ queryKey: ['patient-picker'] });
    void qc.invalidateQueries({ queryKey: ['patients'] });
    pick(patient);
  };

  return (
    <>
      <Modal title={canAttend ? "Nueva consulta — elegir paciente" : "Buscar paciente"} onClose={close} initialFocusRef={searchInputRef}>
        <button
          className="btn primary"
          style={{ width: '100%', marginBottom: 14 }}
          onClick={() => setShowNew(true)}
          type="button"
        >
          <Icon name="plus" size={16} strokeWidth={2.2} /> Nuevo paciente
        </button>

        <div className="search">
          <Icon name="search" />
          <input
            ref={searchInputRef}
            autoFocus
            placeholder="Buscar por nombre o cédula…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>

        <div className="row between" style={{ margin: '14px 0 6px' }}>
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
          <p className="muted" style={{ fontSize: 13 }}>Cargando pacientes…</p>
        ) : list.length > 0 ? (
          <div className="stack" style={{ gap: 4 }}>
            {list.map((p) => (
              <button key={p.id} className="ws-option" onClick={() => pick(p)} type="button">
                <span className="avatar-sm" style={{ background: avatarColor(p.id) }}>
                  {initials(p.name)}
                </span>
                <span className="wo-meta" style={{ flex: 1 }}>
                  <b>{p.name}</b>
                  <span>
                    <span className="mono">CI {p.id_number ?? '—'}</span>
                    {p.age != null ? ` · ${p.age} años` : ''}
                    {p.last_visit_at ? ` · última visita ${formatDate(p.last_visit_at)}` : ' · primera visita'}
                  </span>
                </span>
                <Icon name="arrow" size={16} />
              </button>
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
          <div className="stack" style={{ gap: 10, marginTop: 4 }}>
            <p className="muted" style={{ fontSize: 13 }}>
              {isSearching ? 'Sin resultados para esa búsqueda.' : 'No hay pacientes todavía.'}
            </p>
            <button className="btn primary" onClick={() => setShowNew(true)} type="button">
              <Icon name="plus" size={16} /> Registrar nuevo paciente
            </button>
          </div>
        )}
      </Modal>

      {showNew && (
        <NewPatientModal onClose={() => setShowNew(false)} onCreated={onPatientCreated} />
      )}
    </>
  );
}
