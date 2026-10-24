import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { lookupBillingPatients } from '@/api/billing';
import { Icon } from './Icon';
import { avatarColor, initials } from '@/lib/theme';
import type { BillingPatient } from '@/api/types';

interface Props {
  selected: BillingPatient | null;
  onSelect: (patient: BillingPatient | null) => void;
}

/**
 * Minimal patient finder for the cashier (caja). Searches by name/cédula through
 * the billing-scoped lookup and shows ONLY name + cédula — never allergies or
 * clinical data (LODP). Distinct from the clinical PatientPicker on purpose.
 */
export function BillingPatientPicker({ selected, onSelect }: Props) {
  const [term, setTerm] = useState('');

  const { data: results } = useQuery({
    queryKey: ['billing-patients', term],
    queryFn: () => lookupBillingPatients(term),
    enabled: term.trim().length >= 2,
  });

  if (selected) {
    return (
      <div className="ws-option active" style={{ cursor: 'default' }}>
        <span className="wo-ic"><Icon name="user" size={17} /></span>
        <span className="wo-meta">
          <b>{selected.name}</b>
          <span>CI {selected.id_number ?? '—'}</span>
        </span>
        <button className="btn sm ghost" type="button" onClick={() => { setTerm(''); onSelect(null); }}>
          Cambiar
        </button>
      </div>
    );
  }

  return (
    <div>
      <label className="field-label">Paciente (facturación)</label>
      <div className="search">
        <Icon name="search" />
        <input
          placeholder="Buscar por nombre o cédula…"
          value={term}
          onChange={(e) => setTerm(e.target.value)}
        />
      </div>
      {results && results.length > 0 && (
        <div className="stack" style={{ gap: 4, marginTop: 8 }}>
          {results.map((p) => (
            <button key={p.id} className="ws-option" type="button" onClick={() => onSelect(p)}>
              <span className="avatar-sm" style={{ background: avatarColor(p.id) }}>{initials(p.name)}</span>
              <span className="wo-meta">
                <b>{p.name}</b>
                <span>CI {p.id_number ?? '—'}</span>
              </span>
            </button>
          ))}
        </div>
      )}
      {results && results.length === 0 && term.trim().length >= 2 && (
        <p className="muted" style={{ fontSize: 12.5, marginTop: 8 }}>Sin coincidencias.</p>
      )}
    </div>
  );
}
