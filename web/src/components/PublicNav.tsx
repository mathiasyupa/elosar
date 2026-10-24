import { useState } from 'react';
import { Link } from 'react-router-dom';
import { AnimatePresence, motion } from 'framer-motion';
import { useAuthStore } from '@/store/auth';
import { BrandGlyph } from '@/components/BrandGlyph';
import { LANDING_LINKS } from '@/features/landing/landingData';
import { t } from '@/i18n/es';

interface Props {
  current?: 'login' | 'register';
}

export function PublicNav({ current }: Props) {
  const token = useAuthStore((state) => state.token);
  const [open, setOpen] = useState(false);

  return (
    <header className="public-nav">
      <nav className="public-nav-inner" aria-label="Navegación pública">
        <Link to="/" className="public-brand" onClick={() => setOpen(false)}>
          <BrandGlyph size={30} />
          <span>
            <strong>{t.appName}</strong>
            <small>Operación médica</small>
          </span>
        </Link>

        <div className="public-nav-links">
          {!current &&
            LANDING_LINKS.map(([label, href]) => (
              <a href={href} key={href}>
                {label}
              </a>
            ))}
        </div>

        <div className="public-nav-actions">
          {token ? (
            <Link to="/dashboard" className="btn primary sm">
              Ir al panel
            </Link>
          ) : (
            <>
              {current !== 'login' && (
                <Link to="/login" className="btn ghost sm">
                  Iniciar sesión
                </Link>
              )}
              <Link to="/crear-cuenta" className="btn primary sm">
                Crear mi espacio
              </Link>
            </>
          )}
        </div>

        <button
          type="button"
          aria-label={open ? 'Cerrar menú' : 'Abrir menú'}
          aria-expanded={open}
          aria-controls="public-mobile-menu"
          onClick={() => setOpen((value) => !value)}
          className="public-menu-button"
        >
          <span className={open ? 'is-open' : undefined} aria-hidden="true">
            <i />
            <i />
          </span>
        </button>
      </nav>

      <AnimatePresence>
        {open && (
          <motion.div
            id="public-mobile-menu"
            className="public-mobile-menu"
            initial={{ height: 0, opacity: 0 }}
            animate={{ height: 'auto', opacity: 1 }}
            exit={{ height: 0, opacity: 0 }}
            transition={{ duration: 0.22 }}
          >
            {!current &&
              LANDING_LINKS.map(([label, href]) => (
                <a href={href} key={href} onClick={() => setOpen(false)}>
                  {label}
                </a>
              ))}
            {token ? (
              <Link className="btn primary" to="/dashboard" onClick={() => setOpen(false)}>
                Ir al panel
              </Link>
            ) : (
              <>
                {current !== 'login' && (
                  <Link to="/login" onClick={() => setOpen(false)}>
                    Iniciar sesión
                  </Link>
                )}
                <Link className="btn primary" to="/crear-cuenta" onClick={() => setOpen(false)}>
                  Crear mi espacio
                </Link>
              </>
            )}
          </motion.div>
        )}
      </AnimatePresence>
    </header>
  );
}
