import { useCallback, useMemo, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { importPatients } from '@/api/patients';
import { Modal } from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/Spinner';
import { useUiStore } from '@/store/ui';
import { parsePatientImportFile, type ImportParseIssue } from '@/lib/importParser';
import { DOCUMENT_COUNTRIES, DOCUMENT_TYPE_LABELS } from '@/i18n/labels';
import type { PatientImportRow, PatientImportResult } from '@/api/types';

type Stage = 'file' | 'review' | 'result';

function issueKey(issue: ImportParseIssue | PatientImportResult) {
  return `${issue.line}:${issue.message}`;
}

export function ImportPatientsModal({ onClose }: { onClose: () => void }) {
  const [rows, setRows] = useState<PatientImportRow[]>([]);
  const [issues, setIssues] = useState<ImportParseIssue[]>([]);
  const [excludedLines, setExcludedLines] = useState<number[]>([]);
  const [serverIssues, setServerIssues] = useState<PatientImportResult[]>([]);
  const [stage, setStage] = useState<Stage>('file');
  const [parsing, setParsing] = useState(false);
  const [parseError, setParseError] = useState<string | null>(null);
  const qc = useQueryClient();
  const toast = useUiStore((s) => s.toast);

  const preview = useMutation({
    mutationFn: (candidateRows: PatientImportRow[]) => importPatients(candidateRows, true),
    onSuccess: (data) => {
      const failures = data.rows.filter((row) => row.status === 'error');
      setServerIssues(failures);
      if (data.ready) {
        setStage('review');
      }
    },
    onError: () => {
      setParseError('No se pudo validar el lote. Ningún paciente fue creado.');
    },
  });

  const commit = useMutation({
    mutationFn: (candidateRows: PatientImportRow[]) => importPatients(candidateRows),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ['patients'] });
      qc.invalidateQueries({ queryKey: ['patient-celebrations'] });
      setServerIssues(data.rows.filter((row) => row.status === 'error'));
      setStage('result');
      toast(`Importación confirmada: ${data.created} pacientes creados.`, 'success');
    },
    onError: async (error) => {
      const data = (error as { response?: { data?: { data?: { rows?: PatientImportResult[] } } } }).response?.data?.data;
      setServerIssues(data?.rows ?? []);
      setStage('review');
      toast('El lote no se importó. Corrige las incidencias y vuelve a validarlo.', 'error');
    },
  });

  const handleFile = useCallback(async (file: File) => {
    setParsing(true);
    setParseError(null);
    setRows([]);
    setIssues([]);
    setExcludedLines([]);
    setServerIssues([]);
    setStage('file');

    try {
      const parsed = await parsePatientImportFile(file);
      setRows(parsed.rows);
      setIssues(parsed.issues);
    } catch (err) {
      setParseError(err instanceof Error ? err.message : 'No se pudo leer el archivo.');
    } finally {
      setParsing(false);
    }
  }, []);

  const activeRows = useMemo(
    () => rows.filter((row) => row.source_line == null || !excludedLines.includes(row.source_line)),
    [excludedLines, rows],
  );
  const validRows = useMemo(() => activeRows.filter((row) => row.name && row.id_number && row.birth_date), [activeRows]);
  const allIssues = useMemo(() => {
    const combined: Array<ImportParseIssue | PatientImportResult> = [...issues, ...serverIssues];
    return combined.filter((issue, index) =>
      !excludedLines.includes(issue.line)
      && combined.findIndex((entry) => issueKey(entry) === issueKey(issue)) === index,
    );
  }, [excludedLines, issues, serverIssues]);
  const activeIssues = useMemo(
    () => issues.filter((issue) => !excludedLines.includes(issue.line)),
    [excludedLines, issues],
  );
  const canValidate = validRows.length === activeRows.length && activeRows.length > 0 && activeIssues.length === 0;
  const busy = parsing || preview.isPending || commit.isPending;

  const updateIssueRow = (line: number, patch: Partial<PatientImportRow>) => {
    setRows((current) => current.map((row) => row.source_line === line ? { ...row, ...patch } : row));
    setIssues((current) => current.filter((issue) => issue.line !== line));
    setServerIssues((current) => current.filter((issue) => issue.line !== line));
    setStage('file');
  };

  const confirmIssueRow = (line: number) => {
    setIssues((current) => current.filter((issue) => issue.line !== line));
    setServerIssues((current) => current.filter((issue) => issue.line !== line));
    setExcludedLines((current) => current.filter((excludedLine) => excludedLine !== line));
    setStage('file');
  };

  const footer = (() => {
    if (stage === 'result') {
      return <button className="btn primary" type="button" onClick={onClose}>Cerrar</button>;
    }

    if (stage === 'review') {
      return (
        <>
          <button className="btn" type="button" onClick={() => setStage('file')} disabled={busy}>Cambiar archivo</button>
          {allIssues.length > 0 && (
            <button className="btn" type="button" onClick={() => preview.mutate(activeRows)} disabled={!canValidate || busy}>
              {preview.isPending ? 'Validando…' : 'Volver a validar'}
            </button>
          )}
          <button className="btn primary" type="button" onClick={() => commit.mutate(activeRows)} disabled={busy || allIssues.length > 0}>
            {commit.isPending ? 'Importando…' : `Confirmar ${activeRows.length} pacientes`}
          </button>
        </>
      );
    }

    return (
      <>
        <button className="btn" type="button" onClick={onClose} disabled={busy}>Cerrar</button>
        <button className="btn primary" type="button" disabled={!canValidate || busy} onClick={() => preview.mutate(activeRows)}>
          {preview.isPending ? 'Validando…' : `Validar ${activeRows.length} pacientes`}
        </button>
      </>
    );
  })();

  return (
    <Modal
      title={stage === 'review' ? 'Revisar importación' : stage === 'result' ? 'Importación confirmada' : 'Importar pacientes'}
      description="Formatos admitidos: la exportación .xlsx de Elosar o el .xls HTML del sistema anterior. El archivo se valida completo antes de guardar."
      size="lg"
      onClose={onClose}
      pending={busy}
      footer={footer}
    >
      <div className="patient-import">
        {stage === 'file' && (
          <>
            <label className="patient-import-drop-zone">
              <input
                type="file"
                accept=".xlsx,.xls,.html"
                aria-label="Selecciona una exportación de pacientes"
                onChange={(event) => {
                  const file = event.target.files?.[0];
                  if (file) handleFile(file);
                }}
              />
              <strong>Selecciona una exportación de pacientes</strong>
              <span>Usa el .xlsx exportado por Elosar o el .xls HTML del sistema anterior.</span>
            </label>

            {parsing && <Spinner label="Leyendo archivo…" />}
            {parseError && <p className="field-error" role="alert">{parseError}</p>}
          </>
        )}

        {rows.length > 0 && stage !== 'result' && (
          <>
            <div className="patient-import-summary" aria-live="polite">
              <span className="badge blue">{activeRows.length} filas para importar</span>
              <span className={`badge ${allIssues.length ? 'amber' : 'green'}`}>{allIssues.length} incidencias</span>
              {excludedLines.length > 0 && <span className="badge gray">{excludedLines.length} excluidas explícitamente</span>}
              <span className="muted">No se guardará ningún paciente hasta que la validación esté limpia.</span>
            </div>

            {allIssues.length > 0 && (
              <div className="alert amber" role="alert">
                <div className="ai">!</div>
                <div>
                  <b>Hay filas que requieren corrección</b>
                  <p>Corrige el archivo o excluye explícitamente cada fila. El lote restante no se guardará de forma parcial.</p>
                </div>
              </div>
            )}

            <div className="table-scroll patient-import-issues">
              <table className="tbl">
                <thead><tr><th>Fila</th><th>Incidencia</th><th>Acción</th></tr></thead>
                <tbody>
                  {allIssues.length > 0 ? allIssues.map((issue) => (
                    <tr key={issueKey(issue)}>
                      <td className="mono">{issue.line}</td><td>{issue.message}</td>
                      <td data-label="Acción">
                        {(() => {
                          const row = rows.find((candidate) => candidate.source_line === issue.line);
                          if (!row) return <span className="muted">Vuelve a validar</span>;
                          const canEditDocument = !('kind' in issue) || issue.kind === 'document';

                          return (
                            <div className="patient-import-actions">
                              {canEditDocument && <>
                                <select aria-label={`Tipo de documento, fila ${issue.line}`} className="field" value={row.document_type ?? 'other'} disabled={busy} onChange={(event) => updateIssueRow(issue.line, { document_type: event.target.value as PatientImportRow['document_type'] })}>
                                  {Object.entries(DOCUMENT_TYPE_LABELS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                </select>
                                <select aria-label={`País del documento, fila ${issue.line}`} className="field" value={row.document_country ?? 'OT'} disabled={busy} onChange={(event) => updateIssueRow(issue.line, { document_country: event.target.value })}>
                                  {DOCUMENT_COUNTRIES.map(({ code, name }) => <option key={code} value={code}>{name}</option>)}
                                </select>
                                <input aria-label={`Documento, fila ${issue.line}`} className="field mono" value={row.id_number} disabled={busy} onChange={(event) => updateIssueRow(issue.line, { id_number: event.target.value })} />
                                <button className="btn sm primary" type="button" disabled={busy} onClick={() => confirmIssueRow(issue.line)}>Confirmar datos</button>
                              </>}
                              <button className="btn sm ghost" type="button" disabled={busy} onClick={() => setExcludedLines((current) => current.includes(issue.line) ? current : [...current, issue.line])}>Excluir fila</button>
                            </div>
                          );
                        })()}
                      </td>
                    </tr>
                  )) : (
                    <tr><td colSpan={3} className="muted">Sin incidencias detectadas. Puedes confirmar el lote.</td></tr>
                  )}
                </tbody>
              </table>
            </div>

            <div className="table-scroll patient-import-preview">
              <table className="tbl">
                <thead><tr><th>Nombre</th><th>Documento</th><th>Tipo</th><th>País</th><th>Nacimiento</th><th>Dirección</th><th>Correo</th><th>Teléfono</th></tr></thead>
                <tbody>
                  {activeRows.slice(0, 50).map((row, index) => (
                    <tr key={`${row.id_number}-${index}`}>
                      <td>{row.name}</td><td className="mono">{row.id_number}</td><td>{row.document_type ? DOCUMENT_TYPE_LABELS[row.document_type] : '—'}</td><td>{row.document_country ?? '—'}</td><td className="mono">{row.birth_date}</td><td>{row.address ?? '—'}</td><td>{row.email ?? '—'}</td><td className="mono">{row.phone ?? '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {activeRows.length > 50 && <p className="muted">Se muestran las primeras 50 filas; la validación cubrió las {activeRows.length}.</p>}
            </div>
          </>
        )}

        {stage === 'result' && (
            <div className="alert blue" role="status">
              <div className="ai">✓</div>
            <div><b>El lote fue creado de forma completa</b><p>Se importaron {activeRows.length} pacientes. La pantalla se actualizará al cerrar.</p></div>
          </div>
        )}
      </div>
    </Modal>
  );
}
