import { Link } from 'react-router-dom';
import { Icon } from '@/components/Icon';
import { PublicNav } from '@/components/PublicNav';
import { useAuthStore } from '@/store/auth';
import { LandingPlans } from './LandingPlans';
import { ProductCaptureGallery } from './ProductCaptureGallery';
import { RevealOnScroll } from './RevealOnScroll';
import { LandingHeroVideo } from './LandingHeroVideo';
import { WorkspaceModel } from './WorkspaceModel';
import {
  CONTACT_EMAIL,
  ECUADOR_CONTEXT,
  FAQ,
  PRACTICE_PATHS,
  TRUST_POINTS,
  WORKFLOW,
} from './landingData';

export function LandingPage() {
  const token = useAuthStore((state) => state.token);

  return (
    <div className="public-shell landing">
      <PublicNav />

      <main>
        <section className="landing-hero has-video public-container">
          <div className="landing-hero-copy">
          <p className="landing-eyebrow">
            <span className="landing-live-dot" aria-hidden="true" />
            Software médico para Ecuador
          </p>
          <h1>Una cuenta. Tu consultorio y tu clínica.</h1>
          <p className="landing-hero-lead">
            Atiende en tu espacio personal, crea o intégrate a una clínica y cambia de lugar de
            trabajo cuando lo necesites.
          </p>
          <div className="landing-actions">
            {token ? (
              <Link className="btn primary" to="/dashboard">
                Ir al panel <Icon name="arrow" size={15} />
              </Link>
            ) : (
              <>
                <Link className="btn primary" to="/crear-cuenta">
                  Crear mi espacio <Icon name="arrow" size={15} />
                </Link>
                <Link className="btn ghost" to="/login">
                  Iniciar sesión
                </Link>
              </>
            )}
          </div>
          <p className="landing-action-note">
            30 días de prueba completa · sin tarjeta · empiezas con el espacio que necesitas hoy
          </p>
          </div>
          <LandingHeroVideo />
        </section>

        <section className="public-section public-container landing-workspaces" id="espacios">
          <RevealOnScroll className="section-heading landing-section-heading" variant="heading">
            <div>
              <p className="section-index">01 · Libertad para trabajar</p>
              <h2>Tu cuenta te acompaña. El contexto cambia contigo.</h2>
            </div>
            <p>
              Un médico no trabaja siempre de una sola forma. Elosar separa cada práctica sin
              obligarte a mantener usuarios o sesiones distintas.
            </p>
          </RevealOnScroll>

          <WorkspaceModel />

          <RevealOnScroll className="landing-practice-grid" variant="practices">
            {PRACTICE_PATHS.map((path) => (
              <article className="landing-practice-card" key={path.title}>
                <span className="landing-practice-icon"><Icon name={path.icon} size={22} /></span>
                <p>{path.eyebrow}</p>
                <h3>{path.title}</h3>
                <p>{path.description}</p>
                <ul>
                  {path.features.map((feature) => (
                    <li key={feature}><Icon name="check" size={16} />{feature}</li>
                  ))}
                </ul>
              </article>
            ))}
          </RevealOnScroll>
        </section>

        <section className="public-section public-container landing-workflow-section" id="como-funciona">
          <RevealOnScroll className="section-heading landing-section-heading" variant="heading">
            <div>
              <p className="section-index">02 · Una operación conectada</p>
              <h2>La información avanza con la atención.</h2>
            </div>
            <p>
              Agenda, consulta, documentos y cobro comparten el mismo contexto para que el equipo
              no reconstruya la historia del paciente entre herramientas.
            </p>
          </RevealOnScroll>

          <RevealOnScroll className="landing-motion-workflow" variant="workflow">
            <ol className="landing-workflow" aria-label="Flujo de una atención">
              {WORKFLOW.map((step) => (
                <li key={step.title}>
                  <span className="landing-workflow-number">{step.number}</span>
                  <span className="landing-workflow-icon"><Icon name={step.icon} size={20} /></span>
                  <h3>{step.title}</h3>
                  <p>{step.description}</p>
                </li>
              ))}
            </ol>
          </RevealOnScroll>

          <div className="landing-context">
            <div className="landing-context-intro">
              <p className="landing-eyebrow">Hecho para Ecuador</p>
              <h3>Capacidades locales, explicadas con su alcance real.</h3>
              <p>Sin presentar como certificación lo que es una función del producto.</p>
            </div>
            <div className="landing-context-list">
              {ECUADOR_CONTEXT.map(([label, description]) => (
                <article key={label}>
                  <Icon name="check" size={17} />
                  <div><strong>{label}</strong><p>{description}</p></div>
                </article>
              ))}
            </div>
          </div>
        </section>

        <section className="public-section public-container landing-pricing" id="planes">
          <RevealOnScroll className="section-heading landing-section-heading" variant="heading">
            <div>
              <p className="section-index">03 · Planes claros</p>
              <h2>Elige el espacio que corresponde a tu forma de trabajar.</h2>
            </div>
            <p>
              Dos puntos de partida reales. Si tu práctica crece o cambia, tu cuenta sigue siendo
              la misma.
            </p>
          </RevealOnScroll>
          <LandingPlans />
        </section>

        <section className="public-section public-container landing-trust">
          <RevealOnScroll className="section-heading landing-section-heading" variant="heading">
            <div>
              <p className="section-index">04 · Confianza operativa</p>
              <h2>Cada espacio conserva límites claros.</h2>
            </div>
            <p>
              La libertad de cambiar de lugar de trabajo no mezcla sus datos ni las responsabilidades
              de quienes participan.
            </p>
          </RevealOnScroll>
          <RevealOnScroll className="landing-trust-grid" variant="trust">
            {TRUST_POINTS.map(([title, description], index) => (
              <article key={title}>
                <span>0{index + 1}</span>
                <h3>{title}</h3>
                <p>{description}</p>
              </article>
            ))}
          </RevealOnScroll>
        </section>

        <section className="public-section public-container landing-captures">
          <RevealOnScroll className="section-heading landing-section-heading" variant="heading">
            <div>
              <p className="section-index">05 · Capturas al final</p>
              <h2>El producto, por dentro.</h2>
            </div>
            <p>
              Cuatro pantallas del producto, tal como se ven al usarlo. Los pacientes, las
              cédulas y el caso clínico son inventados: aquí no aparece nadie real.
            </p>
          </RevealOnScroll>
          <ProductCaptureGallery />
        </section>

        <section className="public-section public-container landing-faq" id="preguntas">
          <RevealOnScroll className="section-heading landing-section-heading" variant="heading">
            <div>
              <p className="section-index">06 · Preguntas</p>
              <h2>Lo importante, antes de comenzar.</h2>
            </div>
          </RevealOnScroll>
          <div className="faq-list">
            {FAQ.map((item, index) => (
              <details key={item.question}>
                <summary>
                  <span>0{index + 1}</span>
                  {item.question}
                  <i aria-hidden="true">+</i>
                </summary>
                <p>{item.answer}</p>
              </details>
            ))}
          </div>
        </section>

        <RevealOnScroll className="landing-final-cta-reveal" variant="cta">
          <section className="public-section public-container landing-final-cta">
            <div>
              <p className="landing-eyebrow">Empieza con libertad</p>
              <h2>Tu primer espacio no tiene que ser el único.</h2>
              <p>Crea tu cuenta hoy. Podrás sumar una clínica o volver a tu consultorio cuando lo necesites.</p>
            </div>
            <div>
              <Link className="btn primary" to={token ? '/dashboard' : '/crear-cuenta'}>
                {token ? 'Ir al panel' : 'Crear mi espacio'} <Icon name="arrow" size={15} />
              </Link>
              {!token && <small>30 días de prueba completa · sin tarjeta</small>}
            </div>
          </section>
        </RevealOnScroll>
      </main>

      <footer className="public-footer public-container">
        <div>
          <strong>Elosar</strong>
          <span>Operación médica para Ecuador</span>
        </div>
        <div className="public-footer-links">
          <Link to="/terminos">Términos</Link>
          <Link to="/privacidad">Privacidad</Link>
          <a href={`mailto:${CONTACT_EMAIL}`}>{CONTACT_EMAIL}</a>
        </div>
      </footer>
    </div>
  );
}
