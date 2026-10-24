import { useFormContext } from 'react-hook-form';
import type { Specialty } from '@/api/types';
import type { RegisterFormValues } from './schema';
import { TermsConsent } from '../TermsConsent';

interface Props {
  specialties?: Specialty[];
  loadingSpecialties?: boolean;
}

export function StepSpecialty({ specialties, loadingSpecialties }: Props) {
  const {
    watch,
    setValue,
    register,
    formState: { errors },
  } = useFormContext<RegisterFormValues>();
  const selectedIds = watch('specialty_ids') ?? [];

  // Order of selection matters — the first one checked is the primary
  // specialty (printed on prescriptions), so append/remove rather than sort.
  const toggle = (id: number) => {
    const next = selectedIds.includes(id) ? selectedIds.filter((i) => i !== id) : [...selectedIds, id];
    setValue('specialty_ids', next, { shouldValidate: true });
  };

  return (
    <div className="auth-step">
      <div className="auth-step-heading">
        <h3 data-step-heading tabIndex={-1}>Tus especialidades</h3>
        <p>
          Marca todas las que ejerces. La primera que elijas es tu especialidad principal — la que aparece en tus
          recetas.
        </p>
      </div>

      <fieldset
        className="auth-specialty-fieldset"
        aria-describedby={errors.specialty_ids ? 'specialty_ids-error' : undefined}
        aria-invalid={Boolean(errors.specialty_ids)}
      >
        <legend className="sr-only">Especialidades médicas</legend>
        <div className={`auth-specialty-list${errors.specialty_ids ? ' has-error' : ''}`}>
          {loadingSpecialties && <p className="auth-specialty-loading">Cargando…</p>}
          {specialties?.map((s) => {
            const checked = selectedIds.includes(s.id);
            const isPrimary = checked && selectedIds[0] === s.id;
            return (
              <label key={s.id} className={`auth-specialty-option${checked ? ' is-checked' : ''}`}>
                <input type="checkbox" checked={checked} onChange={() => toggle(s.id)} />
                <span>{s.name}</span>
                {isPrimary && <span className="badge accent plain auth-specialty-primary">Principal</span>}
              </label>
            );
          })}
        </div>
        {errors.specialty_ids && (
          <p className="field-error" id="specialty_ids-error">
            {errors.specialty_ids.message}
          </p>
        )}
      </fieldset>

      {/* On the last step, where the account is actually created — asking for
          agreement three screens earlier would be agreement to nothing yet. */}
      <TermsConsent error={errors.accept_terms?.message}>
        <input type="checkbox" {...register('accept_terms')} />
      </TermsConsent>
    </div>
  );
}
