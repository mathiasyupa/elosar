import { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { PublicNav } from '@/components/PublicNav';
import { LEGAL_ENTITY, LEGAL_UPDATED_AT, LEGAL_VERSION } from './legalData';

interface Props {
  title: string;
  /** One line under the title saying what this document is for, in plain words. */
  lead: string;
  children: ReactNode;
}

/**
 * The shell both legal documents share.
 *
 * They live inside the public site rather than on some separate page, because a
 * doctor who clicks "términos" from the signup form and lands somewhere that looks
 * like a different website has just been taught the exact habit that phishing
 * relies on.
 *
 * The version is printed at the top, not buried at the bottom: the account record
 * stores which version was accepted, so the reader has to be able to see which one
 * they are looking at.
 */
export function LegalLayout({ title, lead, children }: Props) {
  return (
    <div className="public-shell">
      <PublicNav />

      <main className="legal-page public-container">
        <header className="legal-head">
          <p className="section-index">Documento legal · versión {LEGAL_VERSION}</p>
          <h1>{title}</h1>
          <p className="legal-lead">{lead}</p>
          <p className="legal-meta">Última actualización: {LEGAL_UPDATED_AT}</p>
        </header>

        <div className="legal-body">{children}</div>

        <footer className="legal-foot">
          <p>
            ¿Dudas sobre este documento? Escribe a{' '}
            <a href={`mailto:${LEGAL_ENTITY.email}`}>{LEGAL_ENTITY.email}</a>.
          </p>
          <p className="legal-crosslink">
            <Link to="/terminos">Términos de servicio</Link>
            <span aria-hidden="true">·</span>
            <Link to="/privacidad">Política de privacidad</Link>
          </p>
        </footer>
      </main>
    </div>
  );
}

/** A numbered section. The number is what a doctor cites when they write to ask. */
export function LegalSection({ n, title, children }: { n: number; title: string; children: ReactNode }) {
  return (
    <section className="legal-section">
      <h2>
        <span className="legal-section-n">{n}</span>
        {title}
      </h2>
      {children}
    </section>
  );
}
