import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { createClinic, fetchInviteCode, joinClinic, regenerateInviteCode } from '@/api/clinics';
import { fetchMe } from '@/api/auth';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import { Modal } from '@/components/ui/Modal';
import { Icon } from '@/components/Icon';
import { workspaceGradient } from '@/lib/theme';
import type { Workspace } from '@/api/types';

function errMsg(e: unknown, fallback: string): string {
  if (isAxiosError(e)) return (e.response?.data as { message?: string })?.message ?? fallback;
  return fallback;
}

export function ClinicsPage() {
  const qc = useQueryClient();
  const toast = useUiStore((s) => s.toast);
  const workspaces = useAuthStore((s) => s.workspaces);
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const setWorkspaces = useAuthStore((s) => s.setWorkspaces);
  const setActiveWorkspace = useAuthStore((s) => s.setActiveWorkspace);

  const [modal, setModal] = useState<null | 'create' | 'join'>(null);
  const [name, setName] = useState('');
  const [code, setCode] = useState('');
  const [copied, setCopied] = useState(false);

  const active = workspaces.find((w) => w.id === activeWorkspaceId);

  const refresh = async (switchTo?: number) => {
    const me = await fetchMe();
    setWorkspaces(me.workspaces);
    if (switchTo) setActiveWorkspace(switchTo);
    void qc.invalidateQueries();
  };

  const create = useMutation({
    mutationFn: () => createClinic(name.trim()),
    onSuccess: async (clinic: Workspace) => {
      setName('');
      setModal(null);
      await refresh(clinic.id);
      toast(`Clínica "${clinic.name}" creada. Eres el propietario.`, 'success');
    },
    onError: (e) => toast(errMsg(e, 'No se pudo crear la clínica.'), 'error'),
  });

  const join = useMutation({
    mutationFn: () => joinClinic(code.trim()),
    onSuccess: async (clinic: Workspace) => {
      setCode('');
      setModal(null);
      await refresh(clinic.id);
      toast(`Te uniste a "${clinic.name}".`, 'success');
    },
    onError: (e) => toast(errMsg(e, 'Código inválido o expirado.'), 'error'),
  });

  // Invite code is owner-only; a 403 just means the active user isn't the owner.
  const inviteQuery = useQuery({
    queryKey: ['invite-code', activeWorkspaceId],
    queryFn: () => fetchInviteCode(activeWorkspaceId!),
    enabled: !!active?.is_clinic,
    retry: false,
  });

  const regenerate = useMutation({
    mutationFn: () => regenerateInviteCode(activeWorkspaceId!),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['invite-code', activeWorkspaceId] });
      toast('Código regenerado. Los códigos anteriores dejaron de funcionar.', 'info');
    },
  });

  const isOwner = !!active?.is_clinic && inviteQuery.isSuccess;

  const switchTo = (id: number) => {
    if (id === activeWorkspaceId) return;
    setActiveWorkspace(id);
    void qc.invalidateQueries();
    const ws = workspaces.find((w) => w.id === id);
    toast(`Cambiaste a "${ws?.name}".`, 'success');
  };

  const copy = (value: string) => {
    void navigator.clipboard?.writeText(value);
    setCopied(true);
    setTimeout(() => setCopied(false), 1800);
  };

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Clínicas</h1>
          <p>Tus espacios de trabajo — cambia entre tu consultorio personal y las clínicas</p>
        </div>
      </div>

      {isOwner && inviteQuery.data && (
        <div className="invite-banner">
          <div className="ib-ic"><Icon name="building" size={20} /></div>
          <div style={{ flex: 1 }}>
            <b>Código de invitación · {active?.name}</b>
            <p className="muted" style={{ fontSize: 12.5 }}>Compártelo para que otros médicos se unan a tu clínica.</p>
          </div>
          <span className="badge accent mono" style={{ fontSize: 15, padding: '8px 16px' }}>{inviteQuery.data}</span>
          <button className="btn sm" onClick={() => copy(inviteQuery.data!)}>
            <Icon name="check" size={14} /> {copied ? 'Copiado' : 'Copiar'}
          </button>
          <button className="btn sm" onClick={() => regenerate.mutate()} disabled={regenerate.isPending}>
            <Icon name="cog" size={14} /> {regenerate.isPending ? '…' : 'Regenerar'}
          </button>
        </div>
      )}

      <div className="clinic-grid">
        {workspaces.map((ws) => {
          const isActive = ws.id === activeWorkspaceId;
          const role = !ws.is_clinic ? 'Consultorio personal' : 'Clínica';
          return (
            <button
              key={ws.id}
              type="button"
              className={`clinic-card ${isActive ? 'active' : ''}`.trim()}
              aria-pressed={isActive}
              onClick={() => switchTo(ws.id)}
            >
              {isActive && (
                <span className="cc-active">
                  <Icon name="check" size={13} strokeWidth={2.6} /> Activo
                </span>
              )}
              <span className="cc-cover" style={{ background: workspaceGradient(ws.id) }}>
                <Icon name={ws.is_clinic ? 'building' : 'user'} size={26} />
              </span>
              <span className="cc-body">
                <b>{ws.name}</b>
                <span className="cc-role">{role}</span>
              </span>
            </button>
          );
        })}

        <button type="button" className="clinic-card action" onClick={() => setModal('create')}>
          <span className="cc-cover dashed"><Icon name="plus" size={26} /></span>
          <span className="cc-body">
            <b>Crear clínica</b>
            <span className="cc-role">Serás el propietario</span>
          </span>
        </button>

        <button type="button" className="clinic-card action" onClick={() => setModal('join')}>
          <span className="cc-cover dashed"><Icon name="users" size={24} /></span>
          <span className="cc-body">
            <b>Unirme con código</b>
            <span className="cc-role">Pídeselo al propietario</span>
          </span>
        </button>
      </div>

      {modal === 'create' && (
        <Modal
          title="Crear clínica"
          onClose={() => setModal(null)}
          footer={
            <>
              <button className="btn" onClick={() => setModal(null)}>Cancelar</button>
              <button className="btn primary" disabled={!name.trim() || create.isPending} onClick={() => create.mutate()}>
                {create.isPending ? 'Creando…' : 'Crear clínica'}
              </button>
            </>
          }
        >
          <label className="field-label">Nombre de la clínica</label>
          <input className="field" autoFocus value={name} onChange={(e) => setName(e.target.value)} placeholder="Ej. Clínica Metropolitana" />
          <p className="muted" style={{ fontSize: 12, marginTop: 10 }}>Podrás invitar a otros médicos con un código una vez creada.</p>
        </Modal>
      )}

      {modal === 'join' && (
        <Modal
          title="Unirme a una clínica"
          onClose={() => setModal(null)}
          footer={
            <>
              <button className="btn" onClick={() => setModal(null)}>Cancelar</button>
              <button className="btn primary" disabled={!code.trim() || join.isPending} onClick={() => join.mutate()}>
                {join.isPending ? 'Uniéndome…' : 'Unirme'}
              </button>
            </>
          }
        >
          <label className="field-label">Código de invitación</label>
          <input
            className="field mono"
            autoFocus
            style={{ textTransform: 'uppercase' }}
            value={code}
            onChange={(e) => setCode(e.target.value.toUpperCase())}
            placeholder="Ej. BMQ9SVDO"
          />
        </Modal>
      )}
    </>
  );
}
