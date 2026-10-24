import { useFormContext } from 'react-hook-form';
import { Input } from '@/components/ui/Input';
import type { RegisterFormValues } from './schema';

interface StepContactProps {
  /** Signing up through Google: the address is fixed and there is no password. */
  googleEmail?: string | null;
}

export function StepContact({ googleEmail }: StepContactProps) {
  const {
    register,
    formState: { errors },
  } = useFormContext<RegisterFormValues>();

  return (
    <div className="auth-step">
      <div className="auth-step-heading">
        <h3 data-step-heading tabIndex={-1}>Datos de contacto</h3>
        <p>
          {googleEmail
            ? 'Entrarás con tu cuenta de Google. Solo necesitamos saber cómo contactarte.'
            : 'Con esto vas a iniciar sesión y te vamos a contactar.'}
        </p>
      </div>

      {googleEmail ? (
        // Shown but not editable: the server checks this address against the
        // Google handoff, so an edit here could only turn into a rejection.
        <div className="auth-google-locked" role="status">
          <strong>Entrarás con Google</strong>
          <p>
            <b>{googleEmail}</b>
          </p>
        </div>
      ) : (
        <Input
          id="email"
          type="email"
          label="Correo electrónico"
          autoFocus
          autoComplete="username"
          error={errors.email?.message}
          {...register('email')}
        />
      )}

      <div className="auth-field-pair">
        <Input id="phone" type="tel" inputMode="tel" autoComplete="tel" label="Teléfono" error={errors.phone?.message} {...register('phone')} />
        <Input id="city" autoComplete="address-level2" label="Ciudad" error={errors.city?.message} {...register('city')} />
      </div>

      {!googleEmail && (
        <Input
          id="password"
          type="password"
          label="Contraseña"
          autoComplete="new-password"
          error={errors.password?.message}
          {...register('password')}
        />
      )}
    </div>
  );
}
