/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_API_URL?: string;
  /** Sentry DSN. When unset, error monitoring stays completely off. */
  readonly VITE_SENTRY_DSN?: string;
  /** Environment label for Sentry issues; defaults to Vite's mode. */
  readonly VITE_SENTRY_ENV?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}

/** Build id, compared with /version.json to reload tabs left on an old build. */
declare const __BUILD_ID__: string;
