import { useState } from 'react';
import { Icon } from '@/components/Icon';
import { useUiStore } from '@/store/ui';
import { downloadWorkbook, type SheetSpec } from '@/lib/excel';

/**
 * Downloads a report as .xlsx. The sheets are built lazily so a screen that
 * never gets exported pays nothing, and so the builder can read whatever the
 * panel has on screen at click time.
 */
export function ExcelButton({
  filename,
  build,
  label = 'Excel',
  disabled,
  className = 'btn sm',
}: {
  filename: string;
  build: () => SheetSpec[] | Promise<SheetSpec[]>;
  label?: string;
  disabled?: boolean;
  className?: string;
}) {
  const [busy, setBusy] = useState(false);
  const toast = useUiStore((s) => s.toast);

  const run = async () => {
    setBusy(true);
    try {
      const sheets = await build();
      if (sheets.every((s) => s.rows.length === 0)) {
        toast('No hay datos para exportar en este período.', 'error');
        return;
      }
      await downloadWorkbook(filename, sheets);
      toast('Reporte descargado.', 'success');
    } catch {
      toast('No se pudo generar el archivo de Excel.', 'error');
    } finally {
      setBusy(false);
    }
  };

  return (
    <button type="button" className={className} onClick={run} disabled={busy || disabled}>
      <Icon name="download" size={14} /> {busy ? 'Generando…' : label}
    </button>
  );
}
