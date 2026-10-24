import type { Workspace } from '@/api/types';
import { monthlyPriceUsd, PLAN_PRICING, seatsLabel } from '@/lib/workspaceAccess';

/**
 * What this space costs and why, for the owner. Operational information, not a
 * checkout: there is no payment flow, and nothing here charges anything.
 */
export function WorkspacePlanCard({ workspace }: { workspace: Workspace | null }) {
  const entitlement = workspace?.entitlement;

  if (!workspace || !entitlement) return null;

  const price = monthlyPriceUsd(entitlement);
  const seats = seatsLabel(entitlement);
  const isClinic = entitlement.plan_code === 'clinic';
  const isUnlimitedClinic = entitlement.plan_code === 'clinic_unlimited';

  return (
    <div className="card card-pad stack">
      <div>
        <h3>{isUnlimitedClinic ? 'Plan Clínica ilimitada' : isClinic ? 'Plan Clínica' : 'Plan Consultorio'}</h3>
        <p className="muted">
          {isUnlimitedClinic
            ? 'Acceso asignado manualmente, sin límite de médicos que atienden.'
            : isClinic
            ? `Incluye ${PLAN_PRICING.clinic.includedClinicalDoctors} médicos activos. Cada médico adicional suma USD ${PLAN_PRICING.clinic.additionalDoctorUsd} al mes.`
            : 'Para un médico atendiendo en su práctica privada.'}
        </p>
      </div>

      <div>
        <strong>{isUnlimitedClinic ? 'Tarifa manual' : `USD ${price} / mes`}</strong>
        {seats && <p className="muted">{seats}</p>}
      </div>

      {entitlement.bundle_eligible && !isUnlimitedClinic && (
        <p className="muted">
          Tienes consultorio y clínica activos: el paquete de ambos es USD {PLAN_PRICING.bundle.monthlyUsd} al mes.
        </p>
      )}

      <p className="muted">
        Recepción, caja, enfermería y administración no ocupan cupo: solo se cuentan los
        médicos que atienden.
      </p>
    </div>
  );
}
