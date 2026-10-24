import { useEffect, useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { useAuthStore } from '@/store/auth';
import { Icon } from './Icon';
import type { Workspace } from '@/api/types';

function subtitle(ws: Workspace): string {
  return ws.is_clinic ? 'Clínica' : 'Personal';
}

export function WorkspaceSwitcher() {
  const workspaces = useAuthStore((state) => state.workspaces);
  const activeWorkspaceId = useAuthStore((state) => state.activeWorkspaceId);
  const setActiveWorkspace = useAuthStore((state) => state.setActiveWorkspace);
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const [open, setOpen] = useState(false);
  const wrapRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    function onClick(event: MouseEvent) {
      if (wrapRef.current && !wrapRef.current.contains(event.target as Node)) {
        setOpen(false);
      }
    }
    document.addEventListener('mousedown', onClick);
    return () => document.removeEventListener('mousedown', onClick);
  }, [open]);

  const active = workspaces.find((ws) => ws.id === activeWorkspaceId) ?? workspaces[0];
  if (!active) return null;

  const select = (id: number) => {
    setActiveWorkspace(id);
    setOpen(false);
    // Tenant data is workspace-specific — drop it so it refetches.
    void queryClient.invalidateQueries();
  };

  const goManage = () => {
    setOpen(false);
    navigate('/clinicas');
  };

  return (
    <div className="ws-wrap" ref={wrapRef}>
      {open && (
        <div className="ws-menu" role="menu" aria-label="Tus lugares de trabajo">
          <div className="ws-menu-label">Tus lugares de trabajo</div>
          {workspaces.map((ws) => (
            <button
              key={ws.id}
              type="button"
              role="menuitemradio"
              aria-checked={ws.id === active.id}
              className={`ws-option ${ws.is_clinic ? 'clinic' : ''} ${ws.id === active.id ? 'active' : ''}`.trim()}
              onClick={() => select(ws.id)}
            >
              <span className="wo-ic">
                <Icon name={ws.is_clinic ? 'building' : 'user'} size={17} />
              </span>
              <span className="wo-meta">
                <b>{ws.name}</b>
                <span>{subtitle(ws)}</span>
              </span>
              <span className="wo-check">
                <Icon name="check" size={16} strokeWidth={2.6} />
              </span>
            </button>
          ))}

          <div className="ws-divider" />
          <button type="button" className="ws-create" onClick={goManage}>
            <Icon name="building" size={16} /> Crear o unirme a una clínica
          </button>
        </div>
      )}

      <button
        type="button"
        className="ws-switch"
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={() => setOpen((v) => !v)}
      >
        <div className="ws-ic">
          <Icon name={active.is_clinic ? 'building' : 'user'} size={18} />
        </div>
        <div className="ws-meta">
          <b>{active.name}</b>
          <span>{subtitle(active)}</span>
        </div>
        <Icon name="chevron" size={16} className="chev" />
      </button>
    </div>
  );
}
