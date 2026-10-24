import { useFormContext } from 'react-hook-form';
import { Input } from '@/components/ui/Input';
import type { RegisterFormValues } from './schema';

export function StepIdentity() {
  const {
    register,
    formState: { errors },
  } = useFormContext<RegisterFormValues>();

  return (
    <div className="auth-step">
      <div className="auth-step-heading">
        <h3 data-step-heading tabIndex={-1}>¿Cómo te llamas?</h3>
        <p>
          Empecemos con tu identificación profesional.
        </p>
      </div>
      <Input id="name" label="Nombre completo" autoFocus autoComplete="name" error={errors.name?.message} {...register('name')} />
      <Input id="id_number" label="Cédula" maxLength={10} error={errors.id_number?.message} {...register('id_number')} />
    </div>
  );
}
