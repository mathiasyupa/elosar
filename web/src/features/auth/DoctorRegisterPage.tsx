import { useEffect, useRef, useState, type ComponentType } from 'react';
import { FormProvider, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { AnimatePresence, motion } from 'framer-motion';
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { exchangeGoogleCode, isPendingVerification, needsRegistration, registerDoctor } from '@/api/auth';
import { fetchSpecialties } from '@/api/catalogs';
import type { Specialty } from '@/api/types';
import { useAuthStore } from '@/store/auth';
import { Icon } from '@/components/Icon';
import { AuthShell } from './components/AuthShell';
import { getSafeRegistrationError, isUsableAuthResponse } from './authExperience';
import {
  registerSchema,
  registerWithGoogleSchema,
  STEPS,
  STEPS_WITH_GOOGLE,
  type RegisterFormValues,
} from './register-wizard/schema';
import { shakeAnimation, stepVariants } from './register-wizard/variants';
import { ProgressSteps } from './register-wizard/ProgressSteps';
import { StepIdentity } from './register-wizard/StepIdentity';
import { StepContact } from './register-wizard/StepContact';
import { StepSpecialty } from './register-wizard/StepSpecialty';
import { DoctorPreviewCard } from './register-wizard/DoctorPreviewCard';

interface StepComponentProps {
  specialties?: Specialty[];
  loadingSpecialties?: boolean;
  googleEmail?: string | null;
}

const STEP_COMPONENTS: ComponentType<StepComponentProps>[] = [StepIdentity, StepContact, StepSpecialty];

export function DoctorRegisterPage() {
  const navigate = useNavigate();
  const sessionToken = useAuthStore((state) => state.token);
  const setSession = useAuthStore((state) => state.setSession);
  const [params] = useSearchParams();
  // Present when the browser came through the Google callback. Redeemed on
  // submit, not here — this only reads the identity behind it to prefill.
  const googleCode = params.get('google');
  const [stepIndex, setStepIndex] = useState(0);
  const [direction, setDirection] = useState(1);
  const [shake, setShake] = useState(false);
  const [serverError, setServerError] = useState<string | null>(null);
  const stepStageRef = useRef<HTMLDivElement>(null);

  const { data: specialties, isLoading: loadingSpecialties } = useQuery({
    queryKey: ['specialties'],
    queryFn: fetchSpecialties,
  });

  // Safe to call again: a handoff with no account behind it is not consumed by
  // the exchange, precisely so this screen can read it.
  const { data: googleIdentity } = useQuery({
    queryKey: ['google-exchange', googleCode],
    queryFn: () => exchangeGoogleCode(googleCode as string),
    enabled: Boolean(googleCode),
    retry: false,
  });

  const googleMode = Boolean(googleCode);
  const googleEmail =
    googleIdentity && needsRegistration(googleIdentity) ? googleIdentity.email : null;

  const form = useForm<RegisterFormValues>({
    resolver: zodResolver(googleMode ? registerWithGoogleSchema : registerSchema),
    mode: 'onChange',
    defaultValues: { specialty_ids: [] },
  });

  const {
    handleSubmit,
    trigger,
    setValue,
    formState: { isSubmitting },
  } = form;

  // Google settles the address and offers a name; both are the server's record,
  // so they are written into the form rather than typed again.
  useEffect(() => {
    if (!googleIdentity || !needsRegistration(googleIdentity)) return;
    setValue('email', googleIdentity.email);
    if (googleIdentity.name) setValue('name', googleIdentity.name);
  }, [googleIdentity, setValue]);

  const steps = googleMode ? STEPS_WITH_GOOGLE : STEPS;
  const isLastStep = stepIndex === steps.length - 1;
  const StepComponent = STEP_COMPONENTS[stepIndex];

  if (sessionToken) return <Navigate to="/dashboard" replace />;

  const goNext = async () => {
    const valid = await trigger(steps[stepIndex].fields, { shouldFocus: true });
    if (!valid) {
      setShake(true);
      window.setTimeout(() => setShake(false), 400);
      return;
    }
    setDirection(1);
    setStepIndex((index) => Math.min(index + 1, steps.length - 1));
    window.requestAnimationFrame(() => {
      stepStageRef.current?.querySelector<HTMLElement>('[data-step-heading]')?.focus();
    });
  };

  const onSubmit = handleSubmit(async ({ password, ...values }) => {
    setServerError(null);
    try {
      const response = googleCode
        ? await registerDoctor({ ...values, google_code: googleCode })
        : await registerDoctor({ ...values, password: password as string });

      // Without Google, no session comes back on purpose: the account exists,
      // but the address has to answer before it can be used.
      if (isPendingVerification(response)) {
        navigate(`/verifica-tu-correo?email=${encodeURIComponent(response.email)}`, { replace: true });
        return;
      }

      // With Google the address is already proven, so there is nothing to wait
      // for — this is the friction the whole flow exists to remove.
      if (!isUsableAuthResponse(response)) {
        setServerError(getSafeRegistrationError());
        return;
      }
      setSession({
        token: response.token,
        user: response.user,
        doctor: response.doctor,
        staffProfile: response.staff_profile,
        workspaces: response.workspaces,
      });
      navigate('/dashboard', { replace: true });
    } catch {
      setServerError(getSafeRegistrationError());
    }
  });

  const advance = () => {
    if (isLastStep) void onSubmit();
    else void goNext();
  };

  return (
    <FormProvider {...form}>
      <AuthShell
        current="register"
        eyebrow="Crear cuenta"
        title="Crear espacio médico."
        description="Configura tu espacio personal para atender, documentar y organizar tu práctica como médico independiente."
        contextSupplement={<DoctorPreviewCard specialties={specialties} />}
      >
      <div className="auth-panel-heading">
        <span>Consultorio personal</span>
        <h2>Tu práctica empieza aquí</h2>
        <p>Completa tres pasos para crear y personalizar tu espacio profesional.</p>
      </div>

      <div className="auth-registration-note" role="note">
        <strong>Antes de emitir documentos</strong>
        <p>Elosar no verifica credenciales profesionales durante el alta. Antes de emitir documentos, completa tu registro profesional y firma en Perfil.</p>
        <p>No constituye una certificación de Elosar.</p>
      </div>

      <ProgressSteps steps={steps} currentIndex={stepIndex} />

        <form
          className="auth-wizard"
          onSubmit={(event) => {
            event.preventDefault();
            advance();
          }}
        >
          <motion.div animate={shake ? shakeAnimation : undefined}>
            <div className="auth-wizard-stage" ref={stepStageRef}>
              <AnimatePresence mode="wait" custom={direction} initial={false}>
                <motion.div
                  key={steps[stepIndex].id}
                  custom={direction}
                  variants={stepVariants}
                  initial="enter"
                  animate="center"
                  exit="exit"
                >
                  <StepComponent
                    specialties={specialties}
                    loadingSpecialties={loadingSpecialties}
                    googleEmail={googleEmail}
                  />
                </motion.div>
              </AnimatePresence>
            </div>
          </motion.div>

          {serverError && (
            <div className="auth-error" role="alert">
              <strong>No pudimos completar el alta</strong>
              <p>{serverError}</p>
            </div>
          )}

          <div className="auth-wizard-actions">
            {stepIndex > 0 ? (
              <button
                type="button"
                className="btn ghost auth-back-button"
                onClick={() => {
                  setDirection(-1);
                  setStepIndex((index) => Math.max(index - 1, 0));
                  window.requestAnimationFrame(() => {
                    stepStageRef.current?.querySelector<HTMLElement>('[data-step-heading]')?.focus();
                  });
                }}
              >
                Atrás
              </button>
            ) : (
              <span aria-hidden="true" />
            )}
            <motion.button
              type="submit"
              className="btn primary auth-submit"
              whileTap={{ scale: 0.98 }}
              disabled={isLastStep && isSubmitting}
              onClick={(event) => {
                event.preventDefault();
                advance();
              }}
            >
              {isLastStep ? (isSubmitting ? 'Creando espacio…' : 'Crear espacio médico') : 'Continuar'}
              {!isLastStep && <Icon name="arrow" size={15} />}
            </motion.button>
          </div>
        </form>
      <p className="auth-panel-footer">
        ¿Ya tienes acceso? <Link to="/login">Inicia sesión</Link>
      </p>
      </AuthShell>
    </FormProvider>
  );
}
