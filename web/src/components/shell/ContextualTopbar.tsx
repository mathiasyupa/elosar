import type { FormEvent, RefObject } from 'react';
import { Icon } from '@/components/Icon';
import { t } from '@/i18n/es';
import { AccountMenu, type AccountMenuProps } from './AccountMenu';
import type { PageAction } from './PageActionContext';
import type { RouteContext } from './routeContext';

interface ContextualTopbarProps {
  context: RouteContext;
  search: string;
  actions: PageAction[];
  account: AccountMenuProps;
  navigationOpen: boolean;
  navigationTriggerRef: RefObject<HTMLButtonElement>;
  onSearchChange: (value: string) => void;
  onSearchSubmit: () => void;
  onOpenQuickSearch: () => void;
  onOpenNavigation: () => void;
}

export function ContextualTopbar({
  context,
  search,
  actions,
  account,
  navigationOpen,
  navigationTriggerRef,
  onSearchChange,
  onSearchSubmit,
  onOpenQuickSearch,
  onOpenNavigation,
}: ContextualTopbarProps) {
  const submitSearch = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    onSearchSubmit();
  };

  return (
    <header className="topbar">
      <button
        type="button"
        className="icon-btn nav-toggle"
        ref={navigationTriggerRef}
        onClick={onOpenNavigation}
        aria-label="Abrir menú"
        aria-controls="primary-navigation"
        aria-expanded={navigationOpen}
      >
        <Icon name="menu" />
      </button>

      <div className="topbar-context">
        <span className="topbar-context-eyebrow">{context.eyebrow}</span>
        <strong className="topbar-context-title">{context.title}</strong>
      </div>

      <form className="search topbar-search" onSubmit={submitSearch}>
        <Icon name="search" />
        <input
          value={search}
          onChange={(event) => onSearchChange(event.target.value)}
          placeholder={t.nav.search}
          aria-label={t.nav.search}
        />
      </form>

      <div className="topbar-right">
        <button
          className="icon-btn search-toggle"
          type="button"
          onClick={onOpenQuickSearch}
          aria-label={t.nav.search}
          title={t.nav.search}
        >
          <Icon name="search" />
        </button>

        {actions.map((action) => (
          <button
            key={action.id}
            className="btn primary topbar-action"
            type="button"
            onClick={action.onInvoke}
          >
            <Icon name={action.icon} size={16} strokeWidth={2.2} />
            <span>{action.label}</span>
          </button>
        ))}

        <AccountMenu {...account} />
      </div>
    </header>
  );
}
