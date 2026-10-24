import { useId } from 'react';
import type { ConsultationType } from '@/api/types';
import { Accordion } from '@/components/ui/Accordion';
import {
  CLINICAL_TEMPLATES,
  CURRENT_CLINICAL_TEMPLATE_VERSION,
  fieldsForTemplate,
  type StructuredExamField,
} from './clinicalTemplates';

export { CONSULTATION_TYPE_LABELS } from './clinicalTemplates';
export const EXAM_TEMPLATES = CLINICAL_TEMPLATES[CURRENT_CLINICAL_TEMPLATE_VERSION];

/**
 * Typed "Exploración" section templates (Ecuador HCU): the fields shown depend on
 * the attention type. Values are stored as a flat key→string map (structured_exam
 * on the medical record), so adding/removing fields never needs a schema change.
 *
 * `general` is the head-to-toe physical exam every consultation shares, and it is
 * the most used template of all — it was the one type with no structure at all,
 * while its free-text field's placeholder already listed these very segments.
 * The free `physical_examination` note stays alongside it: not every finding
 * belongs to a body region, and consultations closed before this existed keep
 * their prose there.
 */
interface StructuredExamFieldsProps {
  type: ConsultationType;
  value: Record<string, string>;
  onChange: (value: Record<string, string>) => void;
}

interface FieldGroup {
  title: string;
  fields: readonly StructuredExamField[];
}

/** Agrupa por `section`. Cuando ningún campo trae sección (plantilla `general`),
 *  cada campo arranca su propio grupo — un segmento corporal por item del
 *  acordeón, en vez de un único bloque con los 6 sueltos. */
function groupFields(fields: readonly StructuredExamField[]): FieldGroup[] {
  const hasAnySection = fields.some((f) => f.section);
  const groups: FieldGroup[] = [];
  for (const f of fields) {
    const startsNewGroup = hasAnySection ? Boolean(f.section) : true;
    if (startsNewGroup || groups.length === 0) {
      groups.push({ title: f.section ?? f.label, fields: [] });
    }
    (groups[groups.length - 1].fields as StructuredExamField[]).push(f);
  }
  return groups;
}

export function StructuredExamFields({ type, value, onChange }: StructuredExamFieldsProps) {
  // The labels used to be bare <label>s next to unlabelled controls: nothing
  // announced them, and clicking one did not focus its field. useId keeps the
  // association unique even if two templates ever render on the same screen.
  const uid = useId();
  const fields = fieldsForTemplate(type);
  const groups = groupFields(fields);

  const set = (key: string, v: string) => onChange({ ...value, [key]: v });

  return (
    <Accordion
      items={groups.map((group) => ({
        key: group.title,
        title: group.title,
        hasContent: group.fields.some((f) => (value[f.key] ?? '').trim() !== ''),
        children: (
          <div className="form-grid exam-grid">
            {group.fields.map((f) => {
              const fieldId = `${uid}-${f.key}`;
              return (
                <div key={f.key} className={f.kind === 'textarea' ? 'full' : undefined}>
                  <label className="field-label" htmlFor={fieldId}>{f.label}</label>
                  {f.kind === 'textarea' ? (
                    <textarea
                      id={fieldId}
                      className="field"
                      rows={2}
                      value={value[f.key] ?? ''}
                      onChange={(e) => set(f.key, e.target.value)}
                    />
                  ) : f.kind === 'boolean' ? (
                    <select
                      id={fieldId}
                      className="field"
                      value={value[f.key] ?? ''}
                      onChange={(e) => set(f.key, e.target.value)}
                    >
                      <option value="">Sin registrar</option>
                      <option value="si">Sí</option>
                      <option value="no">No</option>
                    </select>
                  ) : (
                    <input
                      id={fieldId}
                      className="field"
                      type={f.kind === 'date' ? 'date' : 'text'}
                      placeholder={f.placeholder}
                      value={value[f.key] ?? ''}
                      onChange={(e) => set(f.key, e.target.value)}
                    />
                  )}
                </div>
              );
            })}
          </div>
        ),
      }))}
    />
  );
}
