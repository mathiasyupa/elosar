import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { dismissOnboarding, fetchOnboarding } from '@/api/onboarding';
import { useAuthStore } from '@/store/auth';
import { Card } from '@/components/ui/Card';
import { Icon } from '@/components/Icon';

/**
 * "Primeros pasos" for a new account: the handful of actions that turn an empty
 * dashboard into a working consultorio.
 *
 * Progress comes from the server, derived from real records — a step is only
 * ticked once the patient, the appointment or the prescription PDF actually
 * exists. The step list is also assembled server-side from the caller's
 * permissions, so a cashier sees their own checklist and never a clinical one.
 *
 * Renders nothing at all when: the endpoint is unavailable (frontend deployed
 * ahead of backend), the doctor dismissed it, or every step is done.
 */
export function OnboardingChecklist() {
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const queryClient = useQueryClient();

  const { data } = useQuery({
    queryKey: ['onboarding', activeWorkspaceId],
    queryFn: fetchOnboarding,
    // Cheap EXISTS queries, but no reason to re-ask on every dashboard visit.
    staleTime: 60_000,
  });

  const dismiss = useMutation({
    mutationFn: dismissOnboarding,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['onboarding'] }),
  });

  if (!data || data.dismissed || data.total === 0) return null;
  if (data.completed >= data.total) return null;

  const pct = Math.round((data.completed / data.total) * 100);
  // The step to nudge: first one still pending.
  const nextKey = data.steps.find((s) => !s.done)?.key;

  return (
    <Card className="onboarding">
      <div className="ob-head">
        <div>
          <h2>Primeros pasos</h2>
          <p>
            {data.completed === 0
              ? 'Cuatro o cinco minutos para dejar tu consultorio listo.'
              : `Llevas ${data.completed} de ${data.total}. Ya casi.`}
          </p>
        </div>
        <button
          type="button"
          className="icon-btn"
          onClick={() => dismiss.mutate()}
          disabled={dismiss.isPending}
          aria-label="Ocultar los primeros pasos"
          title="Ocultar"
        >
          <Icon name="x" size={18} />
        </button>
      </div>

      <div
        className="ob-bar"
        role="progressbar"
        aria-valuenow={data.completed}
        aria-valuemin={0}
        aria-valuemax={data.total}
        aria-label="Progreso de los primeros pasos"
      >
        <span style={{ width: `${pct}%` }} />
      </div>

      <ol className="ob-steps">
        {data.steps.map((step) => (
          <li key={step.key} className={`ob-step ${step.done ? 'done' : ''} ${step.key === nextKey ? 'next' : ''}`.trim()}>
            <span className="ob-check" aria-hidden="true">
              {step.done ? <Icon name="check" size={14} strokeWidth={2.6} /> : null}
            </span>
            <div className="ob-text">
              <b>{step.title}</b>
              <span>{step.description}</span>
            </div>
            {step.done ? (
              <span className="ob-done-label">Listo</span>
            ) : (
              <Link className={`btn sm ${step.key === nextKey ? 'primary' : ''}`.trim()} to={step.route}>
                Ir
                <Icon name="arrow" size={14} />
              </Link>
            )}
          </li>
        ))}
      </ol>
    </Card>
  );
}
