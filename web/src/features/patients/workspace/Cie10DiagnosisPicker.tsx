import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { searchCie10 } from '@/api/catalogs';
import { Icon } from '@/components/Icon';
import type { Cie10Code } from '@/api/types';

export type DiagnosisType = 'primary' | 'secondary' | 'presumptive';

export interface DiagnosisRow {
  cie10_code: string;
  cie10_description: string;
  type: DiagnosisType;
}

const DIAGNOSIS_TYPES: { value: DiagnosisType; label: string }[] = [
  { value: 'primary', label: 'Primario' },
  { value: 'secondary', label: 'Secundario' },
  { value: 'presumptive', label: 'Presuntivo' },
];

function buildNarrative(rows: DiagnosisRow[]): string {
  return rows.map((d) => d.cie10_description).filter(Boolean).join('; ');
}

interface Cie10DiagnosisPickerProps {
  diagnoses: DiagnosisRow[];
  diagnosis: string;
  onDiagnosesChange: (rows: DiagnosisRow[]) => void;
  onDiagnosisChange: (text: string) => void;
  /**
   * Elo's CIE-10 for what is already written (ADR-017). Only when the doctor
   * presses the button; the codes come checked against the catalog.
   */
  onSuggest?: () => Promise<{ code: string; description: string }[]>;
}

export function Cie10DiagnosisPicker({
  diagnoses,
  diagnosis,
  onDiagnosesChange,
  onDiagnosisChange,
  onSuggest,
}: Cie10DiagnosisPickerProps) {
  const [suggested, setSuggested] = useState<{ code: string; description: string }[] | null>(null);
  const [suggesting, setSuggesting] = useState(false);
  const [suggestError, setSuggestError] = useState<string | null>(null);

  const askElo = async () => {
    if (!onSuggest) return;
    setSuggesting(true);
    setSuggestError(null);
    try {
      setSuggested(await onSuggest());
    } catch {
      setSuggestError('Elo no pudo sugerir ahora. Busca en el catálogo.');
    } finally {
      setSuggesting(false);
    }
  };

  const [query, setQuery] = useState('');
  const [debouncedQuery, setDebouncedQuery] = useState('');
  const [narrativeTouched, setNarrativeTouched] = useState(false);

  useEffect(() => {
    const t = window.setTimeout(() => setDebouncedQuery(query.trim()), 300);
    return () => window.clearTimeout(t);
  }, [query]);

  const canSearch = debouncedQuery.length >= 2;

  const { data: searchResult, isFetching } = useQuery({
    queryKey: ['cie10', debouncedQuery],
    queryFn: () => searchCie10(debouncedQuery),
    enabled: canSearch,
  });

  const results = searchResult?.data ?? [];
  const meta = searchResult?.meta;

  const addDiagnosis = (code: Cie10Code) => {
    if (diagnoses.some((d) => d.cie10_code === code.code)) {
      setQuery('');
      return;
    }

    const next: DiagnosisRow[] = [
      ...diagnoses,
      { cie10_code: code.code, cie10_description: code.description, type: 'primary' },
    ];
    onDiagnosesChange(next);
    if (!narrativeTouched || !diagnosis.trim()) {
      onDiagnosisChange(buildNarrative(next));
    }
    setQuery('');
  };

  const removeDiagnosis = (code: string) => {
    const next = diagnoses.filter((d) => d.cie10_code !== code);
    onDiagnosesChange(next);
    if (!narrativeTouched) {
      onDiagnosisChange(buildNarrative(next));
    }
  };

  const updateDescription = (code: string, description: string) => {
    onDiagnosesChange(
      diagnoses.map((d) => (d.cie10_code === code ? { ...d, cie10_description: description } : d)),
    );
  };

  const updateType = (code: string, type: DiagnosisType) => {
    onDiagnosesChange(diagnoses.map((d) => (d.cie10_code === code ? { ...d, type } : d)));
  };

  return (
    <div className="stack" style={{ gap: 16 }}>
      <div>
        <label className="field-label" htmlFor="cie10-search">Catálogo CIE-10</label>

        <div className="search">
          <Icon name="search" />
          <input
            id="cie10-search"
            placeholder="Buscar código o descripción…"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
          />
        </div>

        {onSuggest && (
          <div className="cie-elo" aria-live="polite">
            <button type="button" className="btn sm ghost" onClick={askElo} disabled={suggesting}>
              <Icon name="chat" size={14} /> {suggesting ? 'Elo está pensando…' : 'Sugerir con Elo'}
            </button>
            {suggested?.filter((s) => !diagnoses.some((d) => d.cie10_code === s.code)).map((s) => (
              <button
                key={s.code}
                type="button"
                className="cie-elo-chip"
                title="Agregar"
                onClick={() => addDiagnosis({ code: s.code, description: s.description } as Cie10Code)}
              >
                <Icon name="plus" size={12} /> <b>{s.code}</b> {s.description}
              </button>
            ))}
            {suggested && suggested.length === 0 && <span className="muted">Escribe más en la exploración para que Elo sugiera.</span>}
            {suggested && suggested.length > 0 && suggested.every((s) => diagnoses.some((d) => d.cie10_code === s.code)) && (
              <span className="muted">Elo coincide con lo que ya pusiste.</span>
            )}
            {suggestError && <span className="field-error">{suggestError}</span>}
          </div>
        )}

        {canSearch && (
          <div style={{ marginTop: 8 }}>
            {isFetching && (
              <p className="muted" style={{ fontSize: 12.5, marginBottom: 6 }}>
                Buscando…
              </p>
            )}
            {!isFetching && results.length === 0 && (
              <p className="muted" style={{ fontSize: 12.5 }}>
                Sin resultados{debouncedQuery ? ` para «${debouncedQuery}»` : ''}.
              </p>
            )}
            {results.length > 0 && (
              <>
                {meta && (
                  <p className="muted" style={{ fontSize: 12, marginBottom: 6 }}>
                    {meta.truncated
                      ? `Mostrando ${results.length} de ${meta.total} — afina la búsqueda para ver más.`
                      : `${meta.total} código${meta.total === 1 ? '' : 's'} encontrado${meta.total === 1 ? '' : 's'}.`}
                  </p>
                )}
                <div
                  className="stack"
                  style={{
                    gap: 4,
                    maxHeight: 320,
                    overflowY: 'auto',
                    border: '1px solid var(--border)',
                    borderRadius: 10,
                    padding: 4,
                  }}
                >
                  {results.map((c) => (
                    <button key={c.code} className="ws-option" onClick={() => addDiagnosis(c)} type="button">
                      <span className="badge accent mono">{c.code}</span>
                      <span className="wo-meta">
                        <b>{c.description}</b>
                        {c.category && (
                          <span className="muted" style={{ display: 'block', fontSize: 11 }}>
                            {c.category}
                          </span>
                        )}
                      </span>
                    </button>
                  ))}
                </div>
              </>
            )}
          </div>
        )}
      </div>

      {diagnoses.length > 0 && (
        <div className="stack" style={{ gap: 8 }}>
          <label className="field-label">Diagnósticos seleccionados</label>
          {diagnoses.map((d) => (
            <div
              key={d.cie10_code}
              className="form-grid"
              style={{ gridTemplateColumns: 'auto 1fr auto auto', alignItems: 'center', gap: 8 }}
            >
              <span className="badge accent mono">{d.cie10_code}</span>
              <input
                className="field"
                value={d.cie10_description}
                onChange={(e) => updateDescription(d.cie10_code, e.target.value)}
              />
              <select
                className="field"
                value={d.type}
                onChange={(e) => updateType(d.cie10_code, e.target.value as DiagnosisType)}
              >
                {DIAGNOSIS_TYPES.map((t) => (
                  <option key={t.value} value={t.value}>
                    {t.label}
                  </option>
                ))}
              </select>
              <button
                className="icon-btn"
                onClick={() => removeDiagnosis(d.cie10_code)}
                type="button"
                aria-label="Quitar diagnóstico"
              >
                <Icon name="x" size={16} />
              </button>
            </div>
          ))}
        </div>
      )}

      <div>
        <label className="field-label" htmlFor="clinical-impression">Impresión clínica *</label>
        <textarea
          id="clinical-impression"
          className="field"
          placeholder="Describe la impresión clínica…"
          rows={4}
          value={diagnosis}
          onChange={(e) => {
            setNarrativeTouched(true);
            onDiagnosisChange(e.target.value);
          }}
        />
      </div>
    </div>
  );
}

export { buildNarrative };
