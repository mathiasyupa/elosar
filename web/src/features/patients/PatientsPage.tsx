import { useOpenPatient } from '@/lib/openPatient';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useSearchParams } from 'react-router-dom';
import { fetchPatients, fetchAllPatients, buildPatientExportSheet } from '@/api/patients';
import { ExcelButton } from '@/components/ui/ExcelButton';
import { stampedFilename } from '@/lib/excel';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { useMediaQuery } from '@/lib/useMediaQuery';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Modal } from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/Spinner';
import { Icon } from '@/components/Icon';
import { usePageActionRegistration } from '@/components/shell/PageActionContext';
import { ImportPatientsModal } from './ImportPatientsModal';
import { DeletePatientsModal } from './DeletePatientsModal';
import { NewPatientModal } from './NewPatientModal';
import { PatientDirectoryTable } from './PatientDirectoryTable';
import { PatientPreviewPanel } from './PatientPreviewPanel';
import { PatientCelebrations } from './PatientCelebrations';
import { PendingFollowUps } from './PendingFollowUps';
import { ClinicalWorklists } from './ClinicalWorklists';
import { PatientAppointmentsTab } from './PatientAppointmentsTab';
import { t } from '@/i18n/es';
import type { Patient } from '@/api/types';
import { QueryError } from '@/components/ui/QueryError';
import { PatientWorkspaceLink } from '@/components/PatientWorkspaceLink';

type PatientsView = 'list' | 'appointments' | 'celebrations' | 'follow-ups';

const VIEW_PARAMS: PatientsView[] = ['appointments', 'celebrations', 'follow-ups'];

interface PatientSelection {
  workspaceId: number | null;
  patient: Patient;
}

export function PatientsPage() {
  const [params, setParams] = useSearchParams();
  const [search, setSearch] = useState(params.get('q') ?? '');
  const [page, setPage] = useState(1);
  const [attendedFrom, setAttendedFrom] = useState('');
  const [attendedTo, setAttendedTo] = useState('');
  const [showNew, setShowNew] = useState(false);
  const [recentPatient, setRecentPatient] = useState<Patient | null>(null);
  const [showImport, setShowImport] = useState(false);
  const [showDelete, setShowDelete] = useState(false);
  /** Rows ticked for a bulk action. Separate from `selection`, which is what the
      preview panel is showing — looking at a patient is not choosing one. */
  const [checkedIds, setCheckedIds] = useState<Set<number>>(new Set());
  const [selection, setSelection] = useState<PatientSelection | null>(null);
  const [previewOpen, setPreviewOpen] = useState(false);
  const [view, setView] = useState<PatientsView>(
    () => VIEW_PARAMS.find((v) => v === params.get('view')) ?? 'list',
  );
  const openPatient = useOpenPatient();
  const qc = useQueryClient();
  const pageContentRef = useRef<HTMLDivElement>(null);
  const releasePreviewIsolationRef = useRef<(() => void) | null>(null);
  const isMobile = useMediaQuery('(max-width: 760px)');
  const activeWorkspaceId = useAuthStore((state) => state.activeWorkspaceId);
  const toast = useUiStore((state) => state.toast);
  const { can } = useWorkspaceCapabilities();
  const canManagePatients = can('patients.manage');
  const canViewRecords = can('records.view');
  const canManageRecords = can('records.manage');
  const selected =
    selection?.workspaceId === activeWorkspaceId ? selection.patient : null;
  const mobilePreviewOpen = isMobile && previewOpen && selected !== null;
  const newPatientAction = useMemo(
    () =>
      canManagePatients
        ? {
            id: 'patients.new-patient',
            label: 'Nuevo paciente',
            icon: 'plus',
            onInvoke: () => setShowNew(true),
          }
        : null,
    [canManagePatients],
  );
  usePageActionRegistration(newPatientAction);

  const { data, isLoading, isError, isPaused, error, refetch, isFetching } = useQuery({
    queryKey: ['patients', activeWorkspaceId, search, page, attendedFrom, attendedTo],
    queryFn: () => fetchPatients(search, page, undefined, {
      attendedFrom: attendedFrom || undefined,
      attendedTo: attendedTo || undefined,
    }),
    placeholderData: (previousData, previousQuery) =>
      previousQuery?.queryKey[1] === activeWorkspaceId ? previousData : undefined,
    enabled: view === 'list',
  });

  const toggleChecked = (id: number) => {
    setCheckedIds((current) => {
      const next = new Set(current);
      if (!next.delete(id)) next.add(id);
      return next;
    });
  };

  const toggleAllOnPage = () => {
    const onPage = data?.data.map((patient) => patient.id) ?? [];
    setCheckedIds((current) =>
      onPage.every((id) => current.has(id))
        ? new Set([...current].filter((id) => !onPage.includes(id)))
        : new Set([...current, ...onPage]),
    );
  };

  // Changing the search query always restarts from the first page.
  useEffect(() => {
    setPage(1);
  }, [search, attendedFrom, attendedTo]);

  // A selection made on one page must not survive into another list: acting on
  // rows you can no longer see is how the wrong patient gets deleted.
  useEffect(() => {
    setCheckedIds(new Set());
  }, [page, search, attendedFrom, attendedTo, activeWorkspaceId]);

  // When the page changes, drop the current selection so the preview panel
  // shows the first patient of the new page — matching the initial-load default.
  useEffect(() => {
    setSelection(null);
  }, [page]);

  useEffect(() => {
    setSelection(null);
    setPreviewOpen(false);
  }, [activeWorkspaceId]);

  // Keep a sensible default preview: first patient in the list.
  useEffect(() => {
    if (!selected && data?.data.length) {
      setSelection({ workspaceId: activeWorkspaceId, patient: data.data[0]! });
    }
  }, [activeWorkspaceId, data, selected]);

  useEffect(() => {
    if (!mobilePreviewOpen) return;

    const background = pageContentRef.current;
    if (!background) return;

    const hadInert = background.hasAttribute('inert');
    const previousAriaHidden = background.getAttribute('aria-hidden');
    const hadScrollLock = document.body.classList.contains('patient-preview-modal-open');
    background.setAttribute('inert', '');
    background.setAttribute('aria-hidden', 'true');
    document.body.classList.add('patient-preview-modal-open');

    let restored = false;
    const restore = () => {
      if (restored) return;
      restored = true;
      if (!hadInert) background.removeAttribute('inert');
      if (previousAriaHidden === null) background.removeAttribute('aria-hidden');
      else background.setAttribute('aria-hidden', previousAriaHidden);
      if (!hadScrollLock) document.body.classList.remove('patient-preview-modal-open');
    };
    releasePreviewIsolationRef.current = restore;

    return () => {
      restore();
      if (releasePreviewIsolationRef.current === restore) {
        releasePreviewIsolationRef.current = null;
      }
    };
  }, [mobilePreviewOpen]);

  const closeMobilePreview = () => {
    releasePreviewIsolationRef.current?.();
    releasePreviewIsolationRef.current = null;
    setPreviewOpen(false);
  };

  useEffect(() => {
    setView(VIEW_PARAMS.find((candidate) => candidate === params.get('view')) ?? 'list');
  }, [params]);

  const selectView = (nextView: PatientsView) => {
    const next = new URLSearchParams(params);
    if (nextView === 'list') next.delete('view');
    else next.set('view', nextView);
    setParams(next);
    setView(nextView);
  };

  const total = data?.meta.total;
  const isChangingPage = isFetching && data?.meta.current_page !== page;

  return (
    <>
      <div ref={pageContentRef} className="patients-page-content">
        <div className="page-head">
        <div>
          <h1>{t.patients.title}</h1>
          <p>{total != null ? `${total} paciente(s) registrados` : 'Directorio de pacientes'}</p>
        </div>
      </div>

      {recentPatient && (
        <div className="alert blue patient-created-banner" role="status">
          <div>
            <b>{recentPatient.name} ya está registrado.</b>
            <p>Su expediente puede abrirse en una pestaña aparte; esta lista permanece aquí.</p>
          </div>
          <PatientWorkspaceLink className="btn sm" patientId={recentPatient.id}>
            Abrir expediente
          </PatientWorkspaceLink>
        </div>
      )}

      <div className="pill-row patients-view-tabs">
        <button className={`pill ${view === 'list' ? 'active' : ''}`.trim()} onClick={() => selectView('list')} type="button">
          Lista de pacientes
        </button>
        <button className={`pill ${view === 'appointments' ? 'active' : ''}`.trim()} onClick={() => selectView('appointments')} type="button">
          <Icon name="cal" size={14} /> Citas y atenciones
        </button>
        <button className={`pill ${view === 'follow-ups' ? 'active' : ''}`.trim()} onClick={() => selectView('follow-ups')} type="button">
          <Icon name="steth" size={14} /> Seguimiento pendiente
        </button>
        <button className={`pill ${view === 'celebrations' ? 'active' : ''}`.trim()} onClick={() => selectView('celebrations')} type="button">
          <Icon name="gift" size={14} /> Felicita a tu paciente
        </button>
      </div>

      {view === 'appointments' && (
        <>
          <div className="patient-view-intro">
            <h2>Citas y atenciones</h2>
            <p>Visitas agendadas o registradas sin cita. Una atención es una cita completada.</p>
          </div>
          <PatientAppointmentsTab />
        </>
      )}
      {view === 'follow-ups' && (
        <>
          <div className="patient-view-intro">
            <h2>Seguimiento pendiente</h2>
            <p>Controles indicados por el médico que aún necesitan contacto y agendamiento, partos probables y vacunas por aplicar.</p>
          </div>
          {/* One stack, so the call list and the clinical lists keep their distance. */}
          <div className="stack">
            <PendingFollowUps />
            {can('reports.clinical') && (
              <section aria-label="Partos probables y vacunas">
                <ClinicalWorklists />
              </section>
            )}
          </div>
        </>
      )}
      {view === 'celebrations' && (
        <>
          <div className="patient-view-intro">
            <h2>Felicita a tu paciente</h2>
            <p>Comunicación de relación mediante cumpleaños, aniversarios, bienvenida y reactivación.</p>
          </div>
          <PatientCelebrations />
        </>
      )}

      {view === 'list' && (
      <div className="patients-split">
        <Card padded={false}>
          <div className="card-head">
            <div className="search patient-search">
              <Icon name="search" />
              <input
                placeholder={t.patients.search}
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                aria-label={t.patients.search}
              />
            </div>
            {/* Importar y Exportar juntos: son la misma idea en dos direcciones,
                y tenerlos en sitios distintos obliga a buscar el segundo. */}
            <div className="patient-data-actions">
              {canManagePatients && (
                <button className="btn sm" type="button" onClick={() => setShowImport(true)}>
                  <Icon name="upload" size={15} />
                  Importar
                </button>
              )}
              <ExcelButton
                className="btn sm"
                filename={stampedFilename('pacientes')}
                label="Exportar"
                disabled={!data || data.data.length === 0}
                build={async () => {
                  const all = await fetchAllPatients(search, {
                    attendedFrom: attendedFrom || undefined,
                    attendedTo: attendedTo || undefined,
                  });
                  return [buildPatientExportSheet(all)];
                }}
              />
            </div>
          </div>
          <div className="patient-directory-filter-row" role="group" aria-label="Filtrar por atención clínica">
            <span className="patient-directory-filter-label">Filtrar por atención clínica</span>
            <div className="patient-visit-filter">
              <label htmlFor="patients-attended-from">
                Atendidos desde
                <input
                  id="patients-attended-from"
                  type="date"
                  value={attendedFrom}
                  max={attendedTo || undefined}
                  onChange={(event) => setAttendedFrom(event.target.value)}
                />
              </label>
              <label htmlFor="patients-attended-to">
                Hasta
                <input
                  id="patients-attended-to"
                  type="date"
                  value={attendedTo}
                  min={attendedFrom || undefined}
                  onChange={(event) => setAttendedTo(event.target.value)}
                />
              </label>
              {(attendedFrom || attendedTo) && (
                <button
                  className="btn sm ghost"
                  type="button"
                  onClick={() => {
                    setAttendedFrom('');
                    setAttendedTo('');
                  }}
                >
                  Limpiar fechas
                </button>
              )}
            </div>
          </div>

          {checkedIds.size > 0 && (
            <div className="bulk-bar">
              <span>
                <b>{checkedIds.size}</b> {checkedIds.size === 1 ? 'seleccionado' : 'seleccionados'}
              </span>
              <div className="bulk-bar-actions">
                <button className="btn sm ghost" type="button" onClick={() => setCheckedIds(new Set())}>
                  Quitar selección
                </button>
                <button className="btn sm danger" type="button" onClick={() => setShowDelete(true)}>
                  <Icon name="trash" size={15} />
                  Eliminar
                </button>
              </div>
            </div>
          )}

          {isLoading && (
            <div className="card-pad">
              <Spinner label={t.common.loading} />
            </div>
          )}
          {(isError || isPaused) && (
            <div className="card-pad">
              <QueryError
                error={error}
                area="los pacientes"
                onRetry={() => void refetch()}
                retrying={isFetching} paused={isPaused}
              />
            </div>
          )}
          {data && data.data.length === 0 && (
            <div className="card-pad">
              <EmptyState
                icon="users"
                message={t.patients.empty}
                hint="Registra al primer paciente para comenzar su expediente clínico."
                action={
                  canManagePatients ? (
                    <button className="btn primary" type="button" onClick={() => setShowNew(true)}>
                      <Icon name="plus" size={16} />
                      Registrar paciente
                    </button>
                  ) : undefined
                }
              />
            </div>
          )}

          {data && data.data.length > 0 && (
            <PatientDirectoryTable
              patients={data.data}
              selectedId={selected?.id ?? null}
              onSelect={(patient) => {
                setSelection({ workspaceId: activeWorkspaceId, patient });
                setPreviewOpen(isMobile);
              }}
              onOpen={(patient) => openPatient(patient.id)}
              onDelete={canManagePatients ? (p) => { setCheckedIds(new Set([p.id])); setShowDelete(true); } : undefined}
              checkedIds={canManagePatients ? checkedIds : undefined}
              onToggleCheck={canManagePatients ? toggleChecked : undefined}
              onToggleAll={canManagePatients ? toggleAllOnPage : undefined}
            />
          )}

          {data && data.meta.last_page > 1 && (
            <div className="list-more">
              <span className="muted">
                Página {data.meta.current_page} de {data.meta.last_page} · {data.meta.total} pacientes
              </span>
              <div className="pill-row">
                <button
                  className="pill"
                  type="button"
                  disabled={page === 1 || isChangingPage}
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                >
                  Anterior
                </button>
                <button
                  className="pill"
                  type="button"
                  disabled={page === data.meta.last_page || isChangingPage}
                  onClick={() => setPage((p) => p + 1)}
                >
                  Siguiente
                </button>
              </div>
            </div>
          )}
        </Card>

        {!isMobile ? (
          <aside className="patients-aside">
            {selected ? (
              <PatientPreviewPanel
                patient={selected}
                canViewRecords={canViewRecords}
                canManageRecords={canManageRecords}
              />
            ) : (
              <Card>
                <p className="muted patients-aside-empty">
                  Selecciona un paciente para ver su resumen.
                </p>
              </Card>
            )}
          </aside>
        ) : null}
      </div>
      )}

      {showNew && (
        <NewPatientModal
          onClose={() => setShowNew(false)}
          onCreated={(patient) => {
            setShowNew(false);
            qc.invalidateQueries({ queryKey: ['patients'] });
            setRecentPatient(patient);
            try {
              const tab = window.open(
                `/pacientes/${patient.id}/resumen`,
                `patient-workspace-${patient.id}`,
              );
              if (tab) tab.opener = null;
            } catch {
              // A browser can block a popup after the API reply. The visible
              // link above remains the reliable, user-initiated way to open it.
            }
          }}
        />
      )}
      {showImport && <ImportPatientsModal onClose={() => setShowImport(false)} />}
      {showDelete && (
        <DeletePatientsModal
          ids={[...checkedIds]}
          onClose={() => setShowDelete(false)}
          onDone={({ deleted, archived, failed }) => {
            setCheckedIds(new Set(failed.map((patient) => patient.id)));
            qc.invalidateQueries({ queryKey: ['patients'] });
            qc.invalidateQueries({ queryKey: ['patient-celebrations'] });
            setSelection(null);

            const parts = [];
            if (deleted) parts.push(`${deleted} eliminado${deleted === 1 ? '' : 's'}`);
            if (archived) parts.push(`${archived} archivado${archived === 1 ? '' : 's'}`);
            if (failed.length) {
              const names = failed.map((patient) => patient.name).join(', ');
              parts.push(`no se pudo procesar: ${names}`);
            }
            toast(parts.join(' · ') || 'No se quitó ningún paciente', failed.length ? 'error' : 'success');
          }}
        />
      )}
      </div>

      {mobilePreviewOpen && selected ? (
        <Modal
          title={`Vista previa de ${selected.name}`}
          size="lg"
          onClose={closeMobilePreview}
        >
          <div className="patient-preview-modal-content">
            <PatientPreviewPanel
              patient={selected}
              canViewRecords={canViewRecords}
              canManageRecords={canManageRecords}
            />
          </div>
        </Modal>
      ) : null}
    </>
  );
}
