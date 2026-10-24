// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { App } from '@/App';
import { useAuthStore } from '@/store/auth';
import { ChangeTemporaryPasswordPage } from './ChangeTemporaryPasswordPage';

const changeTemporaryPassword = vi.hoisted(() => vi.fn());
vi.mock('@/api/auth', () => ({ changeTemporaryPassword }));

beforeEach(() => {
  changeTemporaryPassword.mockReset();
  useAuthStore.setState({
    token: 'temporary-token',
    legal: { terms_acceptance_required: true, terms_version: '1.0', password_change_required: true },
  });
});

afterEach(() => {
  cleanup();
  useAuthStore.getState().logout();
});

describe('temporary password change', () => {
  it('redirects a direct dashboard visit to the required password page', async () => {
    render(<MemoryRouter initialEntries={['/dashboard']}><App /></MemoryRouter>);

    expect(await screen.findByRole('heading', { name: 'Cambiar clave temporal' })).toBeTruthy();
  });

  it('changes the password, clears the revoked token and returns to login', async () => {
    changeTemporaryPassword.mockResolvedValue({ message: 'Password changed.' });

    render(
      <MemoryRouter initialEntries={['/cambiar-clave-temporal']}>
        <Routes>
          <Route path="/cambiar-clave-temporal" element={<ChangeTemporaryPasswordPage />} />
          <Route path="/login" element={<p>Inicio de sesión</p>} />
        </Routes>
      </MemoryRouter>,
    );

    fireEvent.change(screen.getByLabelText('Clave temporal'), { target: { value: 'temporal-123' } });
    fireEvent.change(screen.getByLabelText('Contraseña nueva'), { target: { value: 'nueva-clave-123' } });
    fireEvent.change(screen.getByLabelText('Repite la contraseña nueva'), { target: { value: 'nueva-clave-123' } });
    fireEvent.click(screen.getByRole('button', { name: 'Guardar contraseña nueva' }));

    await waitFor(() => expect(changeTemporaryPassword).toHaveBeenCalledWith({
      current_password: 'temporal-123',
      password: 'nueva-clave-123',
      password_confirmation: 'nueva-clave-123',
    }));
    expect(await screen.findByText('Inicio de sesión')).toBeTruthy();
    expect(useAuthStore.getState().token).toBeNull();
  });

  it('keeps the session and explains a failed change', async () => {
    changeTemporaryPassword.mockRejectedValue(new Error('invalid current password'));

    render(<MemoryRouter><ChangeTemporaryPasswordPage /></MemoryRouter>);
    fireEvent.change(screen.getByLabelText('Clave temporal'), { target: { value: 'incorrecta' } });
    fireEvent.change(screen.getByLabelText('Contraseña nueva'), { target: { value: 'nueva-clave-123' } });
    fireEvent.change(screen.getByLabelText('Repite la contraseña nueva'), { target: { value: 'nueva-clave-123' } });
    fireEvent.click(screen.getByRole('button', { name: 'Guardar contraseña nueva' }));

    expect((await screen.findByRole('alert')).textContent).toContain('Comprueba la clave temporal');
    expect(useAuthStore.getState().token).toBe('temporary-token');
  });
});
