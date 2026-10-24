import { useCallback, useEffect, useRef, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { Icon } from '@/components/Icon';
import { initials } from '@/lib/theme';
import { fetchSwitchableDoctors } from '@/api/clinic';
import type { SwitchableDoctor } from '@/api/types';

export interface AccountMenuProps {
  displayName: string;
  displaySub: string | null;
  photoUrl: string | null;
  canOpenProfile: boolean;
  theme: 'light' | 'dark';
  onToggleTheme: () => void;
  onLogout: () => void;
  canSwitchDoctor: boolean;
  onSwitchDoctor: (doctor: SwitchableDoctor) => void;
}

export function AccountMenu({
  displayName,
  displaySub,
  photoUrl,
  canOpenProfile,
  theme,
  onToggleTheme,
  onLogout,
  canSwitchDoctor,
  onSwitchDoctor,
}: AccountMenuProps) {
  const [open, setOpen] = useState(false);
  const [doctors, setDoctors] = useState<SwitchableDoctor[] | null>(null);
  const [loadingDoctors, setLoadingDoctors] = useState(false);
  const wrapRef = useRef<HTMLDivElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const location = useLocation();
  const navigate = useNavigate();

  const closeDisclosure = useCallback(() => {
    setOpen(false);
    triggerRef.current?.focus();
  }, []);

  useEffect(() => {
    setOpen((current) => {
      if (current) triggerRef.current?.focus();
      return false;
    });
  }, [location.pathname]);

  useEffect(() => {
    if (!open) return;

    const closeOnOutsideClick = (event: MouseEvent) => {
      if (wrapRef.current && !wrapRef.current.contains(event.target as Node)) {
        closeDisclosure();
      }
    };
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return;
      closeDisclosure();
    };

    document.addEventListener('mousedown', closeOnOutsideClick);
    document.addEventListener('keydown', closeOnEscape);
    return () => {
      document.removeEventListener('mousedown', closeOnOutsideClick);
      document.removeEventListener('keydown', closeOnEscape);
    };
  }, [closeDisclosure, open]);

  const openProfile = () => {
    closeDisclosure();
    navigate('/perfil');
  };

  const toggleTheme = () => {
    closeDisclosure();
    onToggleTheme();
  };

  const logout = () => {
    closeDisclosure();
    onLogout();
  };

  const openSwitchDoctors = async () => {
    setLoadingDoctors(true);
    try { setDoctors(await fetchSwitchableDoctors()); } finally { setLoadingDoctors(false); }
  };

  return (
    <div className="account-menu" ref={wrapRef}>
      <button
        type="button"
        className="account-menu-trigger"
        ref={triggerRef}
        aria-controls="account-disclosure"
        aria-expanded={open}
        aria-label={`Abrir menú de ${displayName}`}
        onClick={() => setOpen((current) => !current)}
      >
        <span className="avatar" aria-hidden="true">
          {photoUrl ? (
            <img src={photoUrl} alt="" className="avatar-img" />
          ) : (
            initials(displayName)
          )}
        </span>
        <span className="account-menu-meta">
          <b>{displayName}</b>
          {displaySub && <span>{displaySub}</span>}
        </span>
        <Icon name="chevron" size={16} className="account-menu-chevron" />
      </button>

      {open && (
        <div className="account-menu-panel" id="account-disclosure">
          <button type="button" className="account-menu-item" onClick={toggleTheme}>
            <Icon name={theme === 'dark' ? 'sun' : 'moon'} size={18} />
            {theme === 'dark' ? 'Tema claro' : 'Tema oscuro'}
          </button>
          {canOpenProfile && (
            <button
              type="button"
              className="account-menu-item"
              onClick={openProfile}
            >
              <Icon name="user" size={18} />
              Mi perfil
            </button>
          )}
          {canSwitchDoctor && (
            <button type="button" className="account-menu-item" onClick={() => void openSwitchDoctors()}>
              <Icon name="user" size={18} /> Cambiar de médico
            </button>
          )}
          {doctors && (
            <div className="account-doctors" aria-label="Médicos de esta clínica">
              <span className="account-doctors-label">Elige quién va a ingresar</span>
              {doctors.map((doctor) => (
                <button key={doctor.email} type="button" className="account-menu-item account-doctor" onClick={() => onSwitchDoctor(doctor)}>
                  <span className="avatar" aria-hidden="true">{doctor.photo_url ? <img src={doctor.photo_url} alt="" className="avatar-img" /> : initials(doctor.name)}</span>
                  <span>{doctor.name}</span>
                </button>
              ))}
              {!loadingDoctors && doctors.length === 0 && <span className="muted">No hay médicos activos.</span>}
            </div>
          )}
          <div className="account-menu-divider" aria-hidden="true" />
          <button
            type="button"
            className="account-menu-item danger"
            onClick={logout}
          >
            <Icon name="logout" size={18} />
            Cerrar sesión
          </button>
        </div>
      )}
    </div>
  );
}
