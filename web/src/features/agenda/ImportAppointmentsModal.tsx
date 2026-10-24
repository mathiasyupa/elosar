import { useCallback, useMemo, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { importAppointments } from '@/api/appointments';
import { Modal } from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/Spinner';
import { useUiStore } from '@/store/ui';
import { mapAppointmentRows, parseHtmlTableFile } from '@/lib/importParser';
import type { AppointmentImportRow } from '@/api/types';

export function ImportAppointmentsModal({ onClose }: { onClose: () => void }) {
  const [rows, setRows] = useState<AppointmentImportRow[]>([]);
  const [parsing, setParsing] = useState(false);
  const [parseError, setParseError] = useState<string | null>(null);
  const qc = useQueryClient();
  const toast = useUiStore((s) => s.toast);

  const mutation = useMutation({
    mutationFn: importAppointments,
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ['appointments'] });
      toast(`Importación completa: ${data.created} creadas, ${data.errors} errores.`, data.errors > 0 ? 'info' : 'success');
      onClose();
    },
    onError: () => {
      toast('No se pudo importar las citas.', 'error');
    },
  });

  const handleFile = useCallback(async (file: File) => {
    setParsing(true);
    setParseError(null);
    setRows([]);

    try {
      const table = await parseHtmlTableFile(file);
      const mapped = mapAppointmentRows(table);
      setRows(mapped);
    } catch (err) {
      setParseError(err instanceof Error ? err.message : 'No se pudo leer el archivo.');
    } finally {
      setParsing(false);
    }
  }, []);

  const validRows = useMemo(() => rows.filter((r) => r.appointment_date && r.id_number), [rows]);
  const invalidCount = rows.length - validRows.length;

  return (
    <Modal
      title="Importar citas"
      size="lg"
      onClose={onClose}
      pending={parsing || mutation.isPending}
      footer={
        <>
          <button className="btn" type="button" onClick={onClose} disabled={mutation.isPending}>
            Cerrar
          </button>
          <button
            className="btn primary"
            type="button"
            disabled={validRows.length === 0 || mutation.isPending}
            onClick={() => mutation.mutate(validRows)}
          >
            {mutation.isPending ? 'Importando…' : `Importar ${validRows.length} citas`}
          </button>
        </>
      }
    >
      <div className="import-modal">
        <label className="drop-zone">
          <input
            type="file"
            accept=".xls,.xlsx,.csv,.html"
            onChange={(e) => {
              const file = e.target.files?.[0];
              if (file) handleFile(file);
            }}
          />
          <span>Arrastra un archivo Excel o haz clic para seleccionarlo</span>
          <small>Se aceptan los formatos .xls, .xlsx y .csv</small>
        </label>

        {parsing && <Spinner label="Leyendo archivo…" />}

        {parseError && <p className="field-error">{parseError}</p>}

        {rows.length > 0 && (
          <>
            <p className="import-summary">
              {rows.length} filas detectadas · {validRows.length} válidas · {invalidCount} con errores
            </p>
            <div className="import-preview">
              <table>
                <thead>
                  <tr>
                    <th>Fecha/hora</th>
                    <th>Documento</th>
                    <th>Estado</th>
                    <th>Servicio</th>
                    <th>Canal</th>
                    <th>Observaciones</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.slice(0, 50).map((row, idx) => (
                    <tr key={idx} className={!row.appointment_date || !row.id_number ? 'invalid' : ''}>
                      <td>{row.appointment_date || '—'}</td>
                      <td>{row.id_number || '—'}</td>
                      <td>{row.status || '—'}</td>
                      <td>{row.service_name || '—'}</td>
                      <td>{row.channel || '—'}</td>
                      <td>{row.notes || '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {rows.length > 50 && <p className="import-more">…y {rows.length - 50} filas más</p>}
            </div>
          </>
        )}
      </div>
    </Modal>
  );
}
