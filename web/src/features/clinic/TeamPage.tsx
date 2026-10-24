import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { fetchClinicTeam, inviteTeamMember, updateTeamMember } from '@/api/clinic';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import { useWorkspaceCapabilities, ROLE_LABELS } from '@/lib/capabilities';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { Icon } from '@/components/Icon';
import { CommissionRulesModal } from './CommissionRulesModal';
import { RolesReferenceModal } from './RolesReferenceModal';
import { avatarColor, initials } from '@/lib/theme';
import { t } from '@/i18n/es';
import type { MembershipRole, TeamMember } from '@/api/types';
import { QueryError } from '@/components/ui/QueryError';
import { whatsappLink } from '@/lib/whatsapp';

const INVITE_ROLES: MembershipRole[] = ['doctor', 'nurse', 'receptionist', 'billing', 'admin'];
const ASSIGNABLE_ROLES: MembershipRole[] = ['doctor', 'nurse', 'receptionist', 'billing', 'admin'];

/** Clinic team roster (/equipo). Owners can invite members, assign one or more
 *  roles (permissions sum) and deactivate them; everyone else sees a read-only
 *  list. */
export function TeamPage() {
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const { isOwner } = useWorkspaceCapabilities();
  const qc = useQueryClient();
  const toast = useUiStore((s) => s.toast);

  const [email, setEmail] = useState('');
  const [role, setRole] = useState<MembershipRole>('receptionist');
  const [inviteLink, setInviteLink] = useState<string | null>(null);
  const [rulesFor, setRulesFor] = useState<{ id: number; name: string } | null>(null);
  const [rolesRefOpen, setRolesRefOpen] = useState(false);

  const { data, isLoading, isError, isPaused, error, refetch, isFetching } = useQuery({
    queryKey: ['clinic-team', activeWorkspaceId],
    queryFn: fetchClinicTeam,
  });

  const invite = useMutation({
    mutationFn: () => inviteTeamMember(email.trim(), role),
    onSuccess: (res) => {
      setInviteLink(`${window.location.origin}${res.redeem_path}`);
      setEmail('');
      toast('Invitación creada. Comparte el enlace con la persona.', 'success');
    },
    onError: () => toast('No se pudo crear la invitación.', 'error'),
  });

  const setRoles = useMutation({
    mutationFn: ({ id, roles }: { id: number; roles: MembershipRole[] }) => updateTeamMember(id, { roles }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['clinic-team'] });
      toast('Roles actualizados.', 'success');
    },
    onError: () => toast('No se pudo actualizar los roles.', 'error'),
  });

  const toggleStatus = useMutation({
    mutationFn: ({ id, status }: { id: number; status: 'active' | 'inactive' }) =>
      updateTeamMember(id, { status }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['clinic-team'] });
      toast('Estado actualizado.', 'success');
    },
    onError: () => toast('No se pudo actualizar el estado.', 'error'),
  });

  const setCommission = useMutation({
    mutationFn: ({ id, commission_pct }: { id: number; commission_pct: number | null }) =>
      updateTeamMember(id, { commission_pct }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['clinic-team'] });
      toast('Comisión actualizada.', 'success');
    },
    onError: () => toast('No se pudo actualizar la comisión.', 'error'),
  });

  // Toggle a role on/off, keeping at least one role assigned.
  const toggleRole = (m: TeamMember, r: MembershipRole, checked: boolean) => {
    const next = checked ? [...m.roles, r] : m.roles.filter((x) => x !== r);
    if (next.length === 0) {
      toast('Cada miembro debe tener al menos un rol.', 'error');
      return;
    }
    setRoles.mutate({ id: m.id, roles: Array.from(new Set(next)) });
  };

  // Save the doctor's revenue-share only when it actually changed and is valid.
  const commitCommission = (m: { id: number; commission_pct: string | null }, raw: string) => {
    const trimmed = raw.trim();
    const value = trimmed === '' ? null : Number(trimmed);
    if (value !== null && (Number.isNaN(value) || value < 0 || value > 100)) return;
    const current = m.commission_pct === null ? null : Number(m.commission_pct);
    if (value !== current) setCommission.mutate({ id: m.id, commission_pct: value });
  };

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Equipo</h1>
          <p>Miembros de la clínica y sus roles</p>
          {isOwner && (
            <p className="muted" style={{ fontSize: 13, marginTop: 6 }}>
              Marca <b>uno o varios roles</b> por persona; los permisos se suman.{' '}
              <button
                type="button"
                className="btn sm ghost"
                style={{ padding: '2px 8px', verticalAlign: 'baseline' }}
                onClick={() => setRolesRefOpen(true)}
              >
                ¿Qué hace cada rol?
              </button>
            </p>
          )}
        </div>
      </div>

      {isOwner && (
        <Card className="enc-section" style={{ marginBottom: 16 }}>
          <h4>Invitar a un miembro</h4>
          <p className="muted" style={{ fontSize: 13, marginBottom: 12 }}>
            Genera un enlace de invitación con un rol inicial. La persona crea su cuenta y queda vinculada a la
            clínica; luego puedes sumarle más roles aquí.
          </p>
          <div className="row" style={{ gap: 10, alignItems: 'flex-end', flexWrap: 'wrap' }}>
            <div style={{ flex: '1 1 240px' }}>
              <label className="field-label">Correo electrónico</label>
              <input
                className="field"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="persona@correo.com"
              />
            </div>
            <div style={{ width: 180 }}>
              <label className="field-label">Rol inicial</label>
              <select className="field" value={role} onChange={(e) => setRole(e.target.value as MembershipRole)}>
                {INVITE_ROLES.map((r) => (
                  <option key={r} value={r}>{ROLE_LABELS[r]}</option>
                ))}
              </select>
            </div>
            <button
              className="btn primary"
              type="button"
              disabled={!email.trim() || invite.isPending}
              onClick={() => invite.mutate()}
            >
              {invite.isPending ? 'Creando…' : 'Crear invitación'}
            </button>
          </div>
          {inviteLink && (
            <div className="alert" style={{ marginTop: 12 }}>
              <div style={{ flex: 1, minWidth: 0 }}>
                <b style={{ fontSize: 12.5 }}>Enlace de invitación</b>
                <p className="mono" style={{ fontSize: 12, wordBreak: 'break-all' }}>{inviteLink}</p>
              </div>
              <button
                className="btn sm ghost"
                type="button"
                onClick={() => { void navigator.clipboard.writeText(inviteLink); toast('Enlace copiado.', 'success'); }}
              >
                Copiar
              </button>
            </div>
          )}
        </Card>
      )}

      {isLoading && <Spinner label={t.common.loading} />}
      {(isError || isPaused) && (
        <QueryError error={error} area="el equipo" onRetry={() => void refetch()} retrying={isFetching} paused={isPaused} />
      )}

      {data && (
        <Card padded={false}>
          <div className="card-head">
            <h3>Miembros ({data.length})</h3>
          </div>
          {data.length === 0 ? (
            <EmptyState
              icon="users"
              message="Aún no hay miembros."
              hint="Usa el formulario de arriba para invitar a tu primer médico, enfermera o recepcionista."
            />
          ) : (
            /* tbl-cards: below 720px each member restacks as a labelled card. */
            <div className="table-scroll team-table-region" role="region" aria-label="Miembros del equipo" tabIndex={0}>
              <table className="tbl tbl-cards team-table">
              <thead>
                <tr>
                  <th>Miembro</th>
                  <th>Roles</th>
                  <th>Contacto</th>
                  <th
                    className="num"
                    title="% de lo cobrado que recibe cada médico; la clínica se queda con el resto. El reparto se ve en Reportes → Liquidación por médico."
                  >
                    Comisión
                  </th>
                  <th className="num">Estado</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {data.map((m) => (
                  <tr key={m.id} style={{ opacity: m.status === 'inactive' ? 0.55 : 1 }}>
                    <td data-label="Miembro">
                      <div className="who">
                        <span className="avatar-sm" style={{ background: avatarColor(m.id) }}>{initials(m.name)}</span>
                        <div style={{ minWidth: 0 }}>
                          <b>{m.name}</b>
                          {m.email && <span className="muted" style={{ display: 'block', fontSize: 12 }}>{m.email}</span>}
                        </div>
                        {m.is_owner && <span className="badge accent plain"><Icon name="shield" size={12} /> Propietario</span>}
                      </div>
                    </td>
                    <td data-label="Roles">
                      {isOwner && !m.is_owner ? (
                        <div className="row wrap" style={{ gap: 10 }}>
                          {ASSIGNABLE_ROLES.map((r) => (
                            <label key={r} className="row" style={{ gap: 5, fontSize: 12.5, cursor: 'pointer' }}>
                              <input
                                type="checkbox"
                                checked={m.roles.includes(r)}
                                disabled={setRoles.isPending}
                                onChange={(e) => toggleRole(m, r, e.target.checked)}
                              />
                              <span className="muted">{ROLE_LABELS[r]}</span>
                            </label>
                          ))}
                        </div>
                      ) : (
                        <span className="muted">{m.roles.map((r) => ROLE_LABELS[r] ?? r).join(', ')}</span>
                      )}
                    </td>
                    <td data-label="Contacto">
                      {m.phone ? (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 4, alignItems: 'flex-start' }}>
                          <span style={{ fontSize: 13, fontWeight: 500 }}>{m.phone}</span>
                          {whatsappLink(m.phone, `¡Hola, ${m.name.split(/\s+/)[0]}! `) && (
                            <a
                              className="btn sm wa"
                              href={whatsappLink(m.phone, `¡Hola, ${m.name.split(/\s+/)[0]}! `)!}
                              target="_blank"
                              rel="noopener noreferrer"
                              title="Escribir por WhatsApp"
                              onClick={(e) => e.stopPropagation()}
                              style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '4px 10px', height: 'auto', fontSize: 12 }}
                            >
                              <Icon name="whatsapp" size={14} />
                              <span>WhatsApp</span>
                            </a>
                          )}
                        </div>
                      ) : (
                        <span className="muted">—</span>
                      )}
                    </td>
                    <td className="num" data-label="Comisión">
                      {m.roles.includes('doctor') ? (
                        isOwner ? (
                          <span className="row" style={{ gap: 4, alignItems: 'center', justifyContent: 'flex-end' }}>
                            <input
                              type="number"
                              min={0}
                              max={100}
                              step="0.01"
                              className="field mono"
                              style={{ maxWidth: 74, padding: '4px 8px', textAlign: 'right' }}
                              defaultValue={m.commission_pct ?? ''}
                              placeholder="—"
                              onBlur={(e) => commitCommission(m, e.target.value)}
                            />
                            <span className="muted">%</span>
                            <button
                              className="btn sm ghost"
                              type="button"
                              title="Reparto por servicio"
                              onClick={() => setRulesFor({ id: m.id, name: m.name })}
                            >
                              <Icon name="cog" size={14} />
                            </button>
                          </span>
                        ) : (
                          <span className="muted">{m.commission_pct !== null ? `${Number(m.commission_pct)}%` : '—'}</span>
                        )
                      ) : (
                        <span className="muted">—</span>
                      )}
                    </td>
                    <td className="num" data-label="Estado">
                      <span className={`badge ${m.status === 'active' ? 'green' : ''} plain`}>
                        {m.status === 'active' ? 'Activo' : 'Inactivo'}
                      </span>
                    </td>
                    <td className="num" data-label="">
                      {isOwner && !m.is_owner && (
                        <button
                          className="btn sm ghost"
                          type="button"
                          onClick={() =>
                            toggleStatus.mutate({ id: m.id, status: m.status === 'active' ? 'inactive' : 'active' })
                          }
                        >
                          {m.status === 'active' ? 'Desactivar' : 'Reactivar'}
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
              </table>
            </div>
          )}
        </Card>
      )}

      {rulesFor && (
        <CommissionRulesModal
          memberId={rulesFor.id}
          memberName={rulesFor.name}
          onSaved={() => qc.invalidateQueries({ queryKey: ['clinic-team'] })}
          onClose={() => setRulesFor(null)}
        />
      )}

      {rolesRefOpen && <RolesReferenceModal onClose={() => setRolesRefOpen(false)} />}
    </>
  );
}
