import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { createMedicalService, fetchMedicalServices } from '@/api/catalogs';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import { Icon } from './Icon';

/** Quick-create suggestions (the clinic still owns its real catalog). */
const SUGGESTIONS = [
  'Consulta general',
  'Consulta especializada',
  'Consulta Gineco-Obstétrica',
  'Control prenatal',
  'Papanicolaou',
  'Ecografía obstétrica',
  'Control de niño sano',
  'Curación',
];

interface Props {
  value: number | null;
  onChange: (id: number | null) => void;
}

/** Pick the service for an appointment from the workspace catalog, or create one inline. */
export function ServicePicker({ value, onChange }: Props) {
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const toast = useUiStore((s) => s.toast);
  const qc = useQueryClient();
  const [creating, setCreating] = useState(false);
  const [name, setName] = useState('');

  const { data: services } = useQuery({
    queryKey: ['medical-services', activeWorkspaceId],
    queryFn: () => fetchMedicalServices(),
  });

  const create = useMutation({
    mutationFn: () => createMedicalService({ name: name.trim() }),
    onSuccess: (svc) => {
      qc.invalidateQueries({ queryKey: ['medical-services'] });
      onChange(svc.id);
      setCreating(false);
      setName('');
      toast('Servicio creado y asignado.', 'success');
    },
    onError: () => toast('No se pudo crear el servicio.', 'error'),
  });

  if (creating) {
    return (
      <div>
        <label className="field-label">Nuevo servicio</label>
        <div className="row" style={{ gap: 8 }}>
          <input
            className="field"
            list="service-suggestions"
            placeholder="Ej. Consulta Gineco-Obstétrica"
            value={name}
            autoFocus
            onChange={(e) => setName(e.target.value)}
          />
          <datalist id="service-suggestions">
            {SUGGESTIONS.map((s) => <option key={s} value={s} />)}
          </datalist>
          <button className="btn primary sm" disabled={!name.trim() || create.isPending} onClick={() => create.mutate()} type="button">
            {create.isPending ? 'Creando…' : 'Crear'}
          </button>
          <button className="btn sm" onClick={() => { setCreating(false); setName(''); }} type="button">Cancelar</button>
        </div>
        <p className="muted" style={{ fontSize: 11.5, marginTop: 4 }}>El precio e IVA los puedes ajustar luego en «Servicios».</p>
      </div>
    );
  }

  return (
    <div>
      <label className="field-label">Servicio</label>
      <div className="row" style={{ gap: 8 }}>
        <select
          className="field"
          value={value ?? ''}
          onChange={(e) => onChange(e.target.value ? Number(e.target.value) : null)}
        >
          <option value="">— Sin servicio —</option>
          {services?.map((s) => (
            <option key={s.id} value={s.id}>{s.name}</option>
          ))}
        </select>
        <button className="btn sm" onClick={() => setCreating(true)} type="button" title="Crear un servicio nuevo">
          <Icon name="plus" size={14} /> Nuevo
        </button>
      </div>
    </div>
  );
}
