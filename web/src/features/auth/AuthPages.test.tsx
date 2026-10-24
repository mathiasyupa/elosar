import { renderToStaticMarkup } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest';
import { ClinicAdminRegisterPage } from './ClinicAdminRegisterPage';
import { DoctorRegisterPage } from './DoctorRegisterPage';
import { LoginPage } from './LoginPage';
import { RegisterChoicePage } from './RegisterChoicePage';
import { StaffRegisterPage } from './StaffRegisterPage';

let consoleError: ReturnType<typeof vi.spyOn>;

beforeAll(() => {
  consoleError = vi.spyOn(console, 'error').mockImplementation((message, ...args) => {
    if (typeof message === 'string' && message.includes('useLayoutEffect does nothing on the server')) return;
    throw new Error([message, ...args].join(' '));
  });
});

afterAll(() => consoleError.mockRestore());

function render(element: React.ReactNode) {
  const queryClient = new QueryClient();
  return renderToStaticMarkup(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>{element}</MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('public authentication pages', () => {
  it('links login visitors to public account creation', () => {
    const markup = render(<LoginPage />);

    expect(markup).toContain('Accede a tu espacio');
    expect(markup).toContain('¿Todavía no tienes una cuenta?');
    expect(markup).toContain('<a href="/crear-cuenta">Crear cuenta</a>');
    expect(markup).not.toContain('Solicitar acceso');
    expect(markup).toContain('aria-label="Mostrar contraseña"');
    expect(markup).toContain('aria-pressed="false"');
    expect(markup).toContain('Operación médica');
  });

  it('explains the two available account types for public account creation', () => {
    const markup = render(<RegisterChoicePage />);

    expect(markup).toContain('Crea tu cuenta');
    expect(markup).toContain('Elige el espacio que vas a crear.');
    expect(markup).not.toContain('Alta autorizada');
    expect(markup).toContain('Soy médico');
    expect(markup).toContain('Administro una clínica');
    expect(markup).toContain('no crea un perfil médico');
  });

  it('shows a profile preview during doctor registration without promising authorised access', () => {
    const doctor = render(<DoctorRegisterPage />);
    const clinic = render(<ClinicAdminRegisterPage />);

    expect(doctor).toContain('Crear espacio médico');
    expect(doctor).toContain('Vista previa de tu perfil');
    expect(doctor).toContain('Elosar no verifica credenciales profesionales durante el alta. Antes de emitir documentos, completa tu registro profesional y firma en Perfil.');
    expect(doctor).toContain('No constituye una certificación de Elosar.');
    expect(doctor).not.toContain('Alta autorizada');
    expect(doctor).not.toContain('acceso habilitado');
    expect(clinic).toContain('Crear espacio de clínica');
    expect(clinic).toContain('cuenta administrativa');
    expect(clinic).not.toContain('Alta autorizada');
    expect(clinic).not.toContain('acceso habilitado');

    const previewIndex = doctor.indexOf('Vista previa de tu perfil');
    expect(previewIndex).toBeGreaterThan(doctor.indexOf('</aside>'));
    expect(previewIndex).toBeLessThan(doctor.indexOf('<section class="auth-panel">'));
    expect(doctor.match(/Vista previa de tu perfil/g)).toHaveLength(1);
  });

  it('treats staff onboarding as a clinic invitation instead of public signup', () => {
    const markup = render(<StaffRegisterPage />);

    expect(markup).toContain('Invitación de clínica');
    expect(markup).toContain('Acceso por invitación.');
    expect(markup).toContain('Pide a la clínica un nuevo enlace');
  });
});
