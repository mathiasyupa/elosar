import { Icon } from '@/components/Icon';
import { RevealOnScroll } from './RevealOnScroll';

export function WorkspaceModel() {
  return (
    <RevealOnScroll ariaLabel="Una cuenta con consultorio y clínica" className="workspace-model" variant="signature">
      <div className="workspace-model-account">
        <span className="workspace-model-icon">
          <Icon name="user" size={22} />
        </span>
        <div>
          <small>Una sola cuenta</small>
          <strong>Tu acceso a Elosar</strong>
        </div>
      </div>

      <div className="workspace-model-connector" aria-hidden="true">
        <span />
        <i>
          <Icon name="refresh" size={17} />
        </i>
        <span />
      </div>

      <div className="workspace-model-spaces">
        <article>
          <span className="workspace-model-icon">
            <Icon name="steth" size={22} />
          </span>
          <div>
            <small>Espacio personal</small>
            <strong>Mi consultorio</strong>
            <p>Tu práctica, tus pacientes y tu operación.</p>
          </div>
          <span className="workspace-model-status">Activo</span>
        </article>
        <article>
          <span className="workspace-model-icon">
            <Icon name="building" size={22} />
          </span>
          <div>
            <small>Espacio compartido</small>
            <strong>Clínica</strong>
            <p>Un equipo con funciones y permisos diferenciados.</p>
          </div>
          <span className="workspace-model-status">2 médicos</span>
        </article>
      </div>

      <p className="workspace-model-note">
        <Icon name="refresh" size={16} />
        <strong>Cambias de espacio sin cambiar de cuenta.</strong>
        Cada lugar conserva su propio contexto de trabajo.
      </p>
    </RevealOnScroll>
  );
}
