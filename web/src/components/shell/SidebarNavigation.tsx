import { useEffect, useRef, useState, type RefObject } from 'react';
import { NavLink } from 'react-router-dom';
import { BrandGlyph } from '@/components/BrandGlyph';
import { Icon } from '@/components/Icon';
import { WorkspaceSwitcher } from '@/components/WorkspaceSwitcher';
import { t } from '@/i18n/es';
import type { NavGroup } from './navigation';

interface SidebarNavigationProps {
  groups: NavGroup[];
  open: boolean;
  roleBadge: string | null;
  returnFocusRef: RefObject<HTMLButtonElement>;
  onClose: () => void;
}

const MOBILE_DRAWER_QUERY = '(max-width: 900px)';
const FOCUSABLE_SELECTOR =
  'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

function navItemClass({ isActive }: { isActive: boolean }): string {
  return `nav-item ${isActive ? 'active' : ''}`.trim();
}

function useMobileDrawer(): boolean {
  const [isMobile, setIsMobile] = useState(() => window.matchMedia(MOBILE_DRAWER_QUERY).matches);

  useEffect(() => {
    const media = window.matchMedia(MOBILE_DRAWER_QUERY);
    const update = () => setIsMobile(media.matches);
    update();
    media.addEventListener('change', update);
    return () => media.removeEventListener('change', update);
  }, []);

  return isMobile;
}

export function SidebarNavigation({
  groups,
  open,
  roleBadge,
  returnFocusRef,
  onClose,
}: SidebarNavigationProps) {
  const isMobile = useMobileDrawer();
  const drawerRef = useRef<HTMLElement>(null);
  const closeRef = useRef<HTMLButtonElement>(null);
  const wasOpenOnMobile = useRef(false);

  useEffect(() => {
    const drawer = drawerRef.current;
    if (!drawer) return;
    if (isMobile && !open) drawer.setAttribute('inert', '');
    else drawer.removeAttribute('inert');
  }, [isMobile, open]);

  useEffect(() => {
    if (!isMobile) {
      wasOpenOnMobile.current = false;
      return;
    }
    if (open) {
      wasOpenOnMobile.current = true;
      closeRef.current?.focus();
      return;
    }
    if (wasOpenOnMobile.current) {
      wasOpenOnMobile.current = false;
      returnFocusRef.current?.focus();
    }
  }, [isMobile, open, returnFocusRef]);

  useEffect(() => {
    const drawer = drawerRef.current;
    if (!drawer || !isMobile || !open) return;

    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        onClose();
        return;
      }
      if (event.key !== 'Tab') return;

      const focusable = Array.from(drawer.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR));
      if (focusable.length === 0) {
        event.preventDefault();
        return;
      }

      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      } else if (!drawer.contains(document.activeElement)) {
        event.preventDefault();
        first.focus();
      }
    };

    drawer.addEventListener('keydown', handleKeyDown);
    return () => drawer.removeEventListener('keydown', handleKeyDown);
  }, [isMobile, onClose, open]);

  return (
    <>
      <button
        type="button"
        className={`nav-backdrop ${open ? 'show' : ''}`}
        aria-hidden="true"
        tabIndex={-1}
        onClick={onClose}
      />

      <aside
        className={`sidebar ${open ? 'open' : ''}`}
        id="primary-navigation"
        ref={drawerRef}
        role={isMobile ? 'dialog' : undefined}
        aria-label={isMobile ? 'Menú principal' : undefined}
        aria-modal={isMobile && open ? true : undefined}
        aria-hidden={isMobile && !open ? true : undefined}
      >
        <div className="brand">
          <BrandGlyph size={34} />
          <div className="brand-copy">
            <div className="brand-name">{t.appName}</div>
            <div className="brand-sub">{t.appTagline}</div>
          </div>
          <button
            type="button"
            className="nav-close"
            ref={closeRef}
            onClick={onClose}
            aria-label="Cerrar menú"
          >
            <Icon name="x" size={20} />
          </button>
        </div>

        <nav className="nav" aria-label="Navegación principal">
          {groups.map((group) => (
            <div className="nav-group" key={group.label}>
              <div className="nav-label">{group.label}</div>
              {group.items.map((item) => (
                <NavLink
                  key={item.to}
                  to={item.to}
                  className={navItemClass}
                  onClick={onClose}
                >
                  <Icon name={item.icon} />
                  <span>{item.label}</span>
                </NavLink>
              ))}
            </div>
          ))}
        </nav>

        <div className="sidebar-foot">
          {roleBadge && (
            <div className="role-badge" title={roleBadge}>
              <Icon name="shield" size={13} />
              <span>{roleBadge}</span>
            </div>
          )}
          <WorkspaceSwitcher />
        </div>
      </aside>
    </>
  );
}
