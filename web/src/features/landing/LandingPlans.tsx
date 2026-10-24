import { Link } from 'react-router-dom';
import { Icon } from '@/components/Icon';
import { FREE_ROLES, PLAN_PRICING, PLANS } from './landingData';
import { RevealOnScroll } from './RevealOnScroll';

export function LandingPlans() {
  return (
    <RevealOnScroll className="landing-plans-wrap" variant="plans">
      <div className="landing-plans-grid">
        {PLANS.map((plan, index) => (
          <article className={`landing-plan-card ${index === 1 ? 'is-featured' : ''}`} key={plan.title}>
            <header>
              <span className="landing-plan-icon">
                <Icon name={plan.icon} size={21} />
              </span>
              <div>
                <p>{plan.eyebrow}</p>
                <h3>{plan.title}</h3>
              </div>
              {index === 1 && <span className="landing-plan-label">Para equipos</span>}
            </header>

            <div className="landing-plan-price" aria-label={`USD ${plan.price} al mes`}>
              <span>USD</span>
              <strong>{plan.price}</strong>
              <small>/ mes</small>
            </div>
            <p className="landing-plan-description">{plan.description}</p>

            <ul>
              {plan.features.map((feature) => (
                <li key={feature}>
                  <Icon name="check" size={17} />
                  {feature}
                </li>
              ))}
            </ul>

            <Link className={`btn ${index === 1 ? 'primary' : 'secondary'}`} to="/crear-cuenta">
              Empezar con este espacio
              <Icon name="arrow" size={15} />
            </Link>
            <small className="landing-plan-trial">30 días de prueba · sin tarjeta</small>
          </article>
        ))}
      </div>

      <aside className="landing-bundle" aria-label="Opción combinada">
        <div className="landing-bundle-icons" aria-hidden="true">
          <span><Icon name="user" size={18} /></span>
          <i>+</i>
          <span><Icon name="building" size={18} /></span>
        </div>
        <div>
          <p>¿Trabajas en los dos?</p>
          <strong>Consultorio + Clínica por USD {PLAN_PRICING.bundle.monthlyUsd} al mes</strong>
          <small>Ambos espacios activos bajo la misma cuenta.</small>
        </div>
        <Link to="/crear-cuenta">
          Crear ambos espacios
          <Icon name="arrow" size={14} />
        </Link>
      </aside>

      <p className="landing-plan-footnote">
        <Icon name="users" size={18} />
        <span>
          <strong>Solo se cuentan los médicos que atienden.</strong>{' '}
          {FREE_ROLES.slice(0, -1).join(', ')} y {FREE_ROLES.at(-1)} pueden formar parte del equipo sin costo adicional.
        </span>
      </p>
    </RevealOnScroll>
  );
}
