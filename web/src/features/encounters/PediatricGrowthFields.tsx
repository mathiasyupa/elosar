import { useId } from 'react';

type Position = 'recumbent_length' | 'standing_height' | '';

export function PediatricGrowthFields({
  headCircumference,
  position,
  onHeadCircumferenceChange,
  onPositionChange,
}: {
  headCircumference: string;
  position: Position;
  onHeadCircumferenceChange: (value: string) => void;
  onPositionChange: (value: Position) => void;
}) {
  const uid = useId();
  return (
    <fieldset className="form-grid">
      <legend className="form-section-title">Mediciones para referencia OMS (0–5 años)</legend>
      <div>
        <label className="field-label" htmlFor={`${uid}-head`}>Perímetro cefálico (cm)</label>
        <input id={`${uid}-head`} className="field mono" inputMode="decimal" value={headCircumference} onChange={(event) => onHeadCircumferenceChange(event.target.value)} />
      </div>
      <div>
        <label className="field-label" htmlFor={`${uid}-position`}>Cómo se midió longitud/talla</label>
        <select id={`${uid}-position`} className="field" value={position} onChange={(event) => onPositionChange(event.target.value as Position)}>
          <option value="">No registrado</option>
          <option value="recumbent_length">Longitud acostado/a</option>
          <option value="standing_height">Talla de pie</option>
        </select>
      </div>
      <p className="muted full">El servidor calcula la referencia OMS al guardar. No establece diagnóstico ni conducta.</p>
    </fieldset>
  );
}
