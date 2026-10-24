# medico-web

Aplicación web para médicos. Consume la API REST de `medico-backend`
(`/api/v1`, autenticación con tokens Sanctum, multi-tenant por workspace).

## Stack

- **Vite + React + TypeScript** (modo strict)
- **Tailwind CSS** para estilos
- **TanStack Query** para data fetching / caché
- **Zustand** (persistido) para el token de sesión y el workspace activo
- **React Hook Form + Zod** para formularios y validación
- **React Router v6** para el ruteo
- **Axios** con interceptores que inyectan `Authorization: Bearer` y
  `X-Workspace-Id` en cada request automáticamente.

## Puesta en marcha

```bash
cp .env.example .env   # ajusta VITE_API_URL si el backend no está en http://localhost
npm install
npm run dev            # http://localhost:5174
```

El backend debe estar corriendo (Docker: `docker compose up -d`) y tener CORS
habilitado para el origen del front (ver `config/cors.php` en medico-backend).

Credenciales de prueba seeded: `test@example.com` / `password`.

## Estructura

```
src/
├── api/           # cliente axios tipado + funciones por recurso
│   ├── client.ts  # instancia axios + interceptores (token, workspace, 401)
│   ├── types.ts   # tipos que reflejan los API Resources del backend
│   ├── auth.ts  appointments.ts  patients.ts  records.ts  invoices.ts
├── components/
│   ├── ui/        # Button, Input, Card, Spinner, StatusBadge
│   ├── AppLayout.tsx  ProtectedRoute.tsx  WorkspaceSwitcher.tsx
├── features/      # pantallas por dominio
│   ├── auth/      agenda/  patients/  invoices/
├── store/         # Zustand (auth + workspace activo)
├── i18n/          # textos en español + helpers de formato (fecha, dinero)
├── App.tsx        # definición de rutas
└── main.tsx       # providers (QueryClient, Router)
```

## Convenciones

- **Código en inglés, UI en español** (mercado Ecuador).
- **Dinero**: los montos llegan como *strings* (bcmath en el backend). No usar
  `parseFloat` para aritmética; mostrarlos con `formatMoney`.
- Cada llamada a datos de tenant requiere un workspace activo; el cliente lo
  envía vía header `X-Workspace-Id` leyéndolo del store.

## Scripts

| Comando | Acción |
|---|---|
| `npm run dev` | servidor de desarrollo (puerto 5174) |
| `npm run build` | typecheck + build de producción |
| `npm run typecheck` | solo verificación de tipos |
