import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import {
  createMedicalService,
  deleteMedicalService,
  fetchMedicalServices,
  updateMedicalService,
  type MedicalServiceInput,
} from '@/api/catalogs';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { Icon } from '@/components/Icon';
import { formatMoney } from '@/i18n/es';
import type { MedicalService } from '@/api/types';

const CATEGORIES: { value: string; label: string }[] = [
  { value: 'general', label: 'General' },
  { value: 'laboratory', label: 'Laboratorio' },
  { value: 'imaging', label: 'Imagenología' },
  { value: 'procedure', label: 'Procedimiento' },
  { value: 'dental', label: 'Odontología' },
];

const SUGGESTIONS = [
  // General / consultas por especialidad
  'Consulta médica general',
  'Consulta de especialidad',
  'Consulta Gineco-Obstétrica',
  'Consulta pediátrica',
  'Consulta de cardiología',
  'Consulta de dermatología',
  'Consulta de traumatología',
  'Consulta de oftalmología',
  'Consulta de otorrinolaringología',
  'Consulta de urología',
  'Consulta de neurología',
  'Consulta de endocrinología',
  'Consulta de psicología',
  'Consulta de nutrición',
  'Control / seguimiento',
  'Consulta de urgencia',
  'Consulta a domicilio',
  'Consulta virtual (telemedicina)',
  'Certificado médico',
  'Chequeo médico general',
  'Chequeo ejecutivo',
  // Laboratorio
  'Biometría hemática completa',
  'Perfil lipídico',
  'Colesterol total',
  'Triglicéridos',
  'Examen general de orina',
  'Glucosa en ayunas',
  'Hemoglobina glicosilada (HbA1c)',
  'Perfil tiroideo (TSH, T3, T4)',
  'Prueba de embarazo (BHCG)',
  'Examen de heces (coproparasitario)',
  'Urocultivo',
  'Perfil hepático',
  'Perfil renal',
  'Creatinina',
  'Ácido úrico',
  'Electrolitos (Na, K, Cl)',
  'PCR (proteína C reactiva)',
  'VIH (prueba rápida)',
  'VDRL',
  'Grupo sanguíneo y factor Rh',
  'PSA (antígeno prostático)',
  // Imagenología
  'Radiografía de tórax',
  'Radiografía de extremidades',
  'Radiografía dental (panorámica)',
  'Ecografía abdominal',
  'Ecografía obstétrica',
  'Ecografía pélvica',
  'Ecografía renal',
  'Ecografía tiroidea',
  'Ecografía de tejidos blandos',
  'Ecografía Doppler',
  'Electrocardiograma',
  'Mamografía',
  'Tomografía computarizada',
  'Resonancia magnética',
  // Procedimientos
  'Curación de heridas',
  'Sutura de heridas',
  'Retiro de puntos',
  'Nebulización',
  'Inyección intramuscular',
  'Cauterización',
  'Drenaje de absceso',
  'Extracción de cuerpo extraño',
  'Colocación de DIU',
  'Papanicolaou',
  'Toma de citología',
  'Control de niño sano',
  'Control prenatal',
  'Vacunación',
  'Test de embarazo rápido',
  // Odontología
  'Consulta odontológica',
  'Limpieza dental (profilaxis)',
  'Extracción dental simple',
  'Extracción de tercer molar (muela del juicio)',
  'Resina dental (obturación)',
  'Endodoncia (tratamiento de conducto)',
  'Corona dental',
  'Blanqueamiento dental',
  'Control de ortodoncia',
];

function emptyForm(): MedicalServiceInput {
  return { name: '', category: 'general', base_price: '', is_active: true };
}

/** Surfaces the backend's actual reason (permission denied, validation, etc.) instead of a generic message. */
function apiError(error: unknown, fallback: string): string {
  if (isAxiosError(error)) {
    const data = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined;
    const firstValidationError = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
    return firstValidationError ?? data?.message ?? fallback;
  }
  return fallback;
}

export function ServicesPage() {
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const toast = useUiStore((s) => s.toast);
  const qc = useQueryClient();
  const [editingId, setEditingId] = useState<number | null>(null);
  const [form, setForm] = useState<MedicalServiceInput>(emptyForm());

  const { data, isLoading, isError } = useQuery({
    queryKey: ['medical-services', activeWorkspaceId],
    queryFn: () => fetchMedicalServices(),
  });

  const save = useMutation({
    mutationFn: () => {
      const payload: MedicalServiceInput = {
        name: form.name.trim(),
        category: form.category || 'general',
        base_price: form.base_price === '' ? 0 : form.base_price,
        is_active: form.is_active,
      };
      return editingId ? updateMedicalService(editingId, payload) : createMedicalService(payload);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['medical-services'] });
      toast(editingId ? 'Servicio actualizado.' : 'Servicio creado.', 'success');
      setForm(emptyForm());
      setEditingId(null);
    },
    onError: (err) => toast(apiError(err, 'No se pudo guardar el servicio.'), 'error'),
  });

  const remove = useMutation({
    mutationFn: (id: number) => deleteMedicalService(id),
    onSuccess: (_data, id) => {
      qc.invalidateQueries({ queryKey: ['medical-services'] });
      toast('Servicio eliminado.', 'success');
      // If we were editing the one just removed, reset the form.
      if (editingId === id) {
        setForm(emptyForm());
        setEditingId(null);
      }
    },
    onError: (err) => toast(apiError(err, 'No se pudo eliminar el servicio.'), 'error'),
  });

  const confirmRemove = (s: MedicalService) => {
    if (window.confirm(`¿Eliminar "${s.name}"? Esta acción no se puede deshacer. Las facturas ya emitidas conservan su descripción.`)) {
      remove.mutate(s.id);
    }
  };

  const startEdit = (s: MedicalService) => {
    setEditingId(s.id);
    setForm({ name: s.name, category: s.category ?? 'general', base_price: s.base_price, is_active: s.is_active });
  };

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Servicios</h1>
          <p>Los servicios que ofreces (consultas, procedimientos…). Se usan en la cita y en la facturación.</p>
        </div>
      </div>

      <div className="patients-split services-workspace">
        <Card padded={false}>
          {isLoading && <div className="card-pad"><Spinner label="Cargando…" /></div>}
          {isError && <div className="card-pad"><p style={{ color: 'var(--red)', fontSize: 13 }}>No se pudieron cargar los servicios.</p></div>}
          {data && data.length === 0 && <div className="card-pad"><p className="muted">Aún no hay servicios. Crea el primero a la derecha.</p></div>}
          {data && data.length > 0 && (
            <div className="table-scroll" role="region" aria-label="Listado de servicios" tabIndex={0}>
              <table className="tbl tbl-cards">
                <thead>
                  <tr><th>Servicio</th><th>Categoría</th><th>Precio</th><th /></tr>
                </thead>
                <tbody>
                  {data.map((s) => (
                    <tr
                      key={s.id}
                      className={`row-link ${editingId === s.id ? 'selected' : ''}`.trim()}
                      onClick={(e) => {
                        if ((e.target as HTMLElement).closest('button, a, input, select')) return;
                        startEdit(s);
                      }}
                    >
                      <td data-label="Servicio"><b>{s.name}</b></td>
                      <td className="muted" data-label="Categoría">{CATEGORIES.find((c) => c.value === s.category)?.label ?? s.category ?? '—'}</td>
                      <td className="mono" data-label="Precio">{formatMoney(s.base_price)}</td>
                      <td className="num" data-label="">
                        <div className="row" style={{ gap: 6, justifyContent: 'flex-end' }}>
                          <button className="btn sm" onClick={() => startEdit(s)} type="button"><Icon name="edit" size={14} /> Editar</button>
                          <button
                            className="btn sm ghost ghost-danger"
                            onClick={() => confirmRemove(s)}
                            disabled={remove.isPending}
                            type="button"
                            title="Eliminar servicio"
                            aria-label={`Eliminar ${s.name}`}
                          >
                            <Icon name="trash" size={14} />
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>

        <aside className="patients-aside">
          <Card>
            <h3 style={{ marginTop: 0, fontSize: 15 }}>{editingId ? 'Editar servicio' : 'Nuevo servicio'}</h3>
            <div className="stack" style={{ gap: 10 }}>
              <div>
                <label className="field-label" htmlFor="service-name">Nombre *</label>
                <input id="service-name" className="field" list="svc-suggestions" value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} />
                <datalist id="svc-suggestions">{SUGGESTIONS.map((s) => <option key={s} value={s} />)}</datalist>
              </div>
              <div>
                <label className="field-label" htmlFor="service-category">Categoría</label>
                <select id="service-category" className="field" value={form.category ?? 'general'} onChange={(e) => setForm((f) => ({ ...f, category: e.target.value }))}>
                  {CATEGORIES.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                </select>
              </div>
              <div>
                <label className="field-label" htmlFor="service-price">Precio (USD)</label>
                <input id="service-price" className="field mono" inputMode="decimal" placeholder="0.00" value={form.base_price ?? ''} onChange={(e) => setForm((f) => ({ ...f, base_price: e.target.value }))} />
              </div>
              <label className="row" style={{ gap: 8, fontSize: 13 }}>
                <input type="checkbox" checked={form.is_active ?? true} onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))} />
                Activo
              </label>
              <div className="row" style={{ gap: 8 }}>
                <button className="btn primary" disabled={!form.name.trim() || save.isPending} onClick={() => save.mutate()} type="button">
                  {save.isPending ? 'Guardando…' : editingId ? 'Guardar cambios' : 'Crear servicio'}
                </button>
                {editingId && <button className="btn" onClick={() => { setEditingId(null); setForm(emptyForm()); }} type="button">Cancelar</button>}
              </div>
              <p className="muted" style={{ fontSize: 11.5, margin: 0 }}>El precio es opcional; puedes dejarlo en 0 y ajustarlo luego.</p>
            </div>
          </Card>
        </aside>
      </div>
    </>
  );
}
