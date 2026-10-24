/* @vitest-environment jsdom */

import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useAuthStore } from '@/store/auth';
import { PublicNav } from './PublicNav';

describe('PublicNav mobile menu', () => {
  beforeEach(() => {
    vi.stubGlobal('scrollTo', vi.fn());
    useAuthStore.setState({
      token: null,
      user: null,
      doctor: null,
      staffProfile: null,
      workspaces: [],
      activeWorkspaceId: null,
    });
  });

  afterEach(() => vi.unstubAllGlobals());

  it('opens and closes the short mobile menu while exposing workspace creation', async () => {
    const user = userEvent.setup();
    render(
      <MemoryRouter>
        <PublicNav />
      </MemoryRouter>,
    );

    const menuButton = screen.getByRole('button', { name: 'Abrir menú' });
    expect(menuButton.getAttribute('aria-expanded')).toBe('false');
    expect(menuButton.getAttribute('aria-controls')).toBe('public-mobile-menu');
    expect(screen.getAllByRole('link', { name: 'Crear mi espacio' })).toHaveLength(1);
    expect(screen.getByRole('link', { name: 'Espacios' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Cómo funciona' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Planes' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Preguntas' })).toBeTruthy();

    await user.click(menuButton);

    expect(screen.getByRole('button', { name: 'Cerrar menú' }).getAttribute('aria-expanded')).toBe('true');
    expect(screen.getAllByRole('link', { name: 'Crear mi espacio' })).toHaveLength(2);

    await user.click(screen.getByRole('button', { name: 'Cerrar menú' }));

    expect(screen.getByRole('button', { name: 'Abrir menú' }).getAttribute('aria-expanded')).toBe('false');
    await waitFor(() => {
      expect(screen.getAllByRole('link', { name: 'Crear mi espacio' })).toHaveLength(1);
    });
  });
});
