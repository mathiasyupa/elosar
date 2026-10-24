// @vitest-environment jsdom
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createElement, Fragment, useRef, useState, type ReactElement } from 'react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { AccountMenu, type AccountMenuProps } from './AccountMenu';
import { ContextualTopbar } from './ContextualTopbar';
import { buildVisibleNavigation } from './navigation';
import { SidebarNavigation } from './SidebarNavigation';

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

function renderInShell(element: ReactElement) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  return render(
    createElement(
      MemoryRouter,
      {
        future: {
          v7_startTransition: true,
          v7_relativeSplatPath: true,
        },
      },
      createElement(QueryClientProvider, { client: queryClient }, element),
    ),
  );
}

function stubMobileViewport() {
  vi.stubGlobal(
    'matchMedia',
    vi.fn((query: string) => ({
      matches: query === '(max-width: 900px)',
      media: query,
      onchange: null,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      addListener: vi.fn(),
      removeListener: vi.fn(),
      dispatchEvent: vi.fn(),
    })),
  );
}

const account: AccountMenuProps = {
  displayName: 'Ana Torres',
  displaySub: 'Medicina general',
  photoUrl: null,
  canOpenProfile: true,
  theme: 'light',
  onToggleTheme: vi.fn(),
  onLogout: vi.fn(),
  canSwitchDoctor: false,
  onSwitchDoctor: vi.fn(),
};

function DrawerHarness() {
  const [open, setOpen] = useState(false);
  const triggerRef = useRef<HTMLButtonElement>(null);

  return createElement(
    Fragment,
    null,
    createElement(ContextualTopbar, {
      context: { eyebrow: 'Operaciones', title: 'Agenda' },
      search: '',
      actions: [],
      account,
      navigationOpen: open,
      navigationTriggerRef: triggerRef,
      onSearchChange: () => undefined,
      onSearchSubmit: () => undefined,
      onOpenQuickSearch: () => undefined,
      onOpenNavigation: () => setOpen(true),
    }),
    createElement(SidebarNavigation, {
      groups: [],
      open,
      roleBadge: null,
      returnFocusRef: triggerRef,
      onClose: () => setOpen(false),
    }),
  );
}

describe('buildVisibleNavigation', () => {
  it('keeps daily operations visible while respecting permissions', () => {
    const groups = buildVisibleNavigation({
      isClinic: false,
      isDoctorAccount: true,
      can: (permission) => permission !== 'billing.view',
      canAny: (permissions) => permissions.some((permission) => permission !== 'billing.view'),
    });
    const labels = groups.flatMap((group) => group.items.map((item) => item.label));
    expect(labels).toContain('Inicio');
    expect(labels).toContain('Agenda');
    expect(labels).toContain('Pacientes');
    expect(labels).not.toContain('Facturación');
    expect(labels).not.toContain('Equipo');
  });

  it('shows clinic-only destinations in a clinic with permission', () => {
    const groups = buildVisibleNavigation({
      isClinic: true,
      isDoctorAccount: false,
      can: () => true,
      canAny: () => true,
    });
    const labels = groups.flatMap((group) => group.items.map((item) => item.label));
    expect(labels).toContain('Equipo');
    expect(labels).not.toContain('Perfil Profesional');
  });
});

describe('mobile shell accessibility', () => {
  it('keeps the closed drawer inert and restores focus after its modal keyboard cycle', async () => {
    stubMobileViewport();
    const user = userEvent.setup();
    renderInShell(createElement(DrawerHarness));

    const trigger = screen.getByRole('button', { name: 'Abrir menú' });
    const drawer = document.getElementById('primary-navigation');
    expect(drawer).not.toBeNull();
    expect(trigger.getAttribute('aria-expanded')).toBe('false');
    expect(drawer?.getAttribute('aria-hidden')).toBe('true');
    expect(drawer?.hasAttribute('inert')).toBe(true);

    await user.click(trigger);

    expect(trigger.getAttribute('aria-expanded')).toBe('true');
    expect(drawer?.getAttribute('aria-hidden')).toBeNull();
    expect(drawer?.hasAttribute('inert')).toBe(false);
    const close = screen.getByRole('button', { name: 'Cerrar menú' });
    await waitFor(() => expect(document.activeElement).toBe(close));

    await user.tab();
    expect(document.activeElement).toBe(close);

    await user.keyboard('{Escape}');

    expect(trigger.getAttribute('aria-expanded')).toBe('false');
    expect(drawer?.getAttribute('aria-hidden')).toBe('true');
    expect(drawer?.hasAttribute('inert')).toBe(true);
    expect(document.activeElement).toBe(trigger);
  });
});

describe('account disclosure accessibility', () => {
  it('uses native Tab order and restores trigger focus after Escape', async () => {
    const user = userEvent.setup();
    renderInShell(createElement(AccountMenu, account));

    const trigger = screen.getByRole('button', { name: 'Abrir menú de Ana Torres' });
    await user.click(trigger);

    expect(screen.queryByRole('menu')).toBeNull();
    expect(screen.queryByRole('menuitem')).toBeNull();
    await user.tab();
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Tema oscuro' }));

    await user.keyboard('{Escape}');

    expect(screen.queryByRole('button', { name: 'Tema oscuro' })).toBeNull();
    expect(document.activeElement).toBe(trigger);
  });

  it('restores trigger focus after changing theme', async () => {
    const user = userEvent.setup();
    renderInShell(createElement(AccountMenu, account));

    const trigger = screen.getByRole('button', { name: 'Abrir menú de Ana Torres' });
    await user.click(trigger);
    await user.tab();
    await user.click(screen.getByRole('button', { name: 'Tema oscuro' }));

    expect(screen.queryByRole('button', { name: 'Tema oscuro' })).toBeNull();
    expect(document.activeElement).toBe(trigger);
  });
});
