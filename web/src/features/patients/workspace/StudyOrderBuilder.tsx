import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { createWorkspaceStudy, searchStudies } from '@/api/studyOrders';
import { Icon } from '@/components/Icon';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import type { Study, StudyOrderItemInput, StudyType } from '@/api/types';

interface StudyOrderBuilderProps {
  type: StudyType;
  items: StudyOrderItemInput[];
  onChange: (items: StudyOrderItemInput[]) => void;
}

/** Accent/case-insensitive normalization for catalog filtering. */
function normalize(s: string): string {
  return s.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase().trim();
}

/**
 * Grouped, searchable checklist of orderable studies (the competitor's
 * Exámenes / Imágenes screens). The doctor ticks catalog studies or adds a
 * custom one (the imaging "Nuevo" button); selection is a controlled list of
 * order items, snapshotting name + category.
 */
export function StudyOrderBuilder({ type, items, onChange }: StudyOrderBuilderProps) {
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const toast = useUiStore((s) => s.toast);
  const qc = useQueryClient();
  const [query, setQuery] = useState('');
  const [manualName, setManualName] = useState('');
  const [manualCategory, setManualCategory] = useState('');

  const { data: catalog = [], isLoading } = useQuery({
    queryKey: ['studies', type, activeWorkspaceId],
    queryFn: () => searchStudies(type),
  });

  const selectedIds = useMemo(
    () => new Set(items.map((i) => i.study_id).filter((id): id is number => id != null)),
    [items],
  );
  const manualItems = items.filter((i) => i.study_id == null);

  // Group the catalog by clinical category, filtered by the search term.
  const groups = useMemo(() => {
    const q = normalize(query);
    const map = new Map<string, Study[]>();
    for (const study of catalog) {
      if (q && !normalize(study.name).includes(q) && !normalize(study.category ?? '').includes(q)) continue;
      const key = study.category ?? 'General';
      const list = map.get(key) ?? [];
      list.push(study);
      map.set(key, list);
    }
    return Array.from(map.entries());
  }, [catalog, query]);

  const toggle = (study: Study) => {
    if (selectedIds.has(study.id)) {
      onChange(items.filter((i) => i.study_id !== study.id));
    } else {
      onChange([...items, { study_id: study.id, name: study.name, category: study.category }]);
    }
  };

  const removeItem = (item: StudyOrderItemInput) => {
    onChange(items.filter((i) => i !== item));
  };

  const saveStudy = useMutation({
    mutationFn: (payload: { name: string; category: string | null }) =>
      createWorkspaceStudy({ type, name: payload.name, category: payload.category }),
    onSuccess: (study) => {
      qc.invalidateQueries({ queryKey: ['studies', type] });
      onChange([...items, { study_id: study.id, name: study.name, category: study.category }]);
      toast('Examen guardado en el catálogo de la clínica.', 'success');
      setManualName('');
      setManualCategory('');
    },
    onError: () => toast('No se pudo guardar el examen.', 'error'),
  });

  const addManual = (persist: boolean) => {
    const name = manualName.trim();
    if (!name) return;
    const category = manualCategory.trim() || null;
    if (persist) {
      saveStudy.mutate({ name, category });
      return;
    }
    onChange([...items, { study_id: null, name, category }]);
    setManualName('');
    setManualCategory('');
  };

  const typeLabel = type === 'imaging' ? 'estudios de imagen' : 'exámenes de laboratorio';

  return (
    <div className="stack" style={{ gap: 16 }}>
      {/* Selected summary */}
      <div className="card-head" style={{ padding: 0 }}>
        <div>
          <h4 style={{ margin: 0, fontSize: 14 }}>
            {type === 'imaging' ? 'Imagenología' : 'Laboratorio'}
          </h4>
          <p className="muted" style={{ fontSize: 12.5, marginTop: 4 }}>
            Marca los {typeLabel} a solicitar o agrega uno que no esté en el catálogo.
          </p>
        </div>
        <span className="badge" title="Exámenes seleccionados">
          {items.length} seleccionado{items.length === 1 ? '' : 's'}
        </span>
      </div>

      {items.length > 0 && (
        <div className="row wrap" style={{ gap: 8 }}>
          {items.map((item, idx) => (
            <span
              key={item.study_id ?? `m-${idx}`}
              className="badge"
              style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}
            >
              {item.name}
              {item.study_id == null && <em style={{ opacity: 0.7, fontStyle: 'normal' }}> · manual</em>}
              <button
                type="button"
                className="icon-btn"
                style={{ width: 16, height: 16 }}
                aria-label={`Quitar ${item.name}`}
                onClick={() => removeItem(item)}
              >
                <Icon name="x" size={12} />
              </button>
            </span>
          ))}
        </div>
      )}

      {/* Search */}
      <div>
        <label className="field-label">Buscar examen</label>
        <div className="search">
          <Icon name="search" />
          <input
            placeholder={type === 'imaging' ? 'Ej. Ecografía, tórax, TAC…' : 'Ej. Biometría, glucosa, TSH…'}
            value={query}
            onChange={(e) => setQuery(e.target.value)}
          />
        </div>
      </div>

      {/* Grouped checklist */}
      {isLoading ? (
        <p className="muted" style={{ fontSize: 13 }}>Cargando catálogo…</p>
      ) : groups.length === 0 ? (
        <p className="muted" style={{ fontSize: 13 }}>Sin coincidencias en el catálogo. Agrégalo manualmente abajo.</p>
      ) : (
        <div className="stack" style={{ gap: 14 }}>
          {groups.map(([category, studies]) => (
            <div key={category}>
              <div
                className="field-label"
                style={{ color: 'var(--accent)', textTransform: 'uppercase', letterSpacing: '.4px', marginBottom: 8 }}
              >
                {category}
              </div>
              <div className="row wrap" style={{ gap: 8 }}>
                {studies.map((study) => {
                  const checked = selectedIds.has(study.id);
                  return (
                    <label
                      key={study.id}
                      className="chip"
                      style={{
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: 6,
                        padding: '6px 10px',
                        borderRadius: 10,
                        border: `1px solid ${checked ? 'var(--accent)' : 'var(--border)'}`,
                        background: checked ? 'var(--accent-soft)' : 'var(--surface)',
                        cursor: 'pointer',
                        fontSize: 12.5,
                      }}
                    >
                      <input type="checkbox" checked={checked} onChange={() => toggle(study)} />
                      {study.name}
                      {!study.is_global && (
                        <span className="muted" style={{ fontSize: 10.5 }}>· clínica</span>
                      )}
                    </label>
                  );
                })}
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Manual / "Nuevo" */}
      <div
        className="enc-section"
        style={{ border: '1px dashed var(--border)', borderRadius: 12, padding: 14, background: 'var(--surface)' }}
      >
        <div className="field-label" style={{ marginBottom: 8 }}>Agregar examen que no está en la lista</div>
        <div className="form-grid" style={{ gap: 10 }}>
          <div>
            <input
              className="field"
              placeholder="Nombre del examen"
              value={manualName}
              onChange={(e) => setManualName(e.target.value)}
            />
          </div>
          <div>
            <input
              className="field"
              placeholder="Categoría (opcional)"
              value={manualCategory}
              onChange={(e) => setManualCategory(e.target.value)}
            />
          </div>
          <div className="row" style={{ gap: 8 }}>
            <button className="btn sm" type="button" disabled={!manualName.trim()} onClick={() => addManual(false)}>
              <Icon name="plus" size={14} /> Agregar a la orden
            </button>
            <button
              className="btn sm ghost"
              type="button"
              disabled={!manualName.trim() || saveStudy.isPending}
              onClick={() => addManual(true)}
              title="Guardar en el catálogo de la clínica para reutilizarlo"
            >
              Guardar en catálogo
            </button>
          </div>
        </div>
      </div>

      {manualItems.length > 0 && (
        <p className="muted" style={{ fontSize: 12 }}>
          {manualItems.length} examen(es) agregados manualmente (no quedan en el catálogo salvo que los guardes).
        </p>
      )}
    </div>
  );
}
