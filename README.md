# Elosar

Historia clínica, agenda y facturación para consultorios y clínicas del **Ecuador**, con
**Elo**, un asistente de IA que propone y el médico decide.

En producción en **[elosar.site](https://elosar.site)**, donde la usa una clínica real.

> **Sobre este repositorio.** Es una **copia pública del código** preparada para el concurso
> *Be a Dragon* (USFQ, categoría Computación). El desarrollo ocurre en dos repositorios
> privados (backend y web). Esta copia se publica **sin historial de git, sin credenciales ni
> datos de producción**, y sin los comandos internos de migración de un cliente. Los nombres
> de personas en pruebas y datos de demostración son ficticios.

## Qué hace

- **Operación clínica:**
  - agenda y sala de espera compartida, donde enfermería toma signos vitales y cualquier
    médico de la clínica los ve;
  - expediente y atención con motivo, enfermedad actual, evolución, examen físico y
    diagnósticos **CIE-10**;
  - recetas, órdenes de exámenes, certificados y vacunas en PDF con membrete;
  - módulo de gineco-obstetricia.
- **Administración:**
  - roles (médico, enfermería, recepción, caja, administración) con permisos propios;
  - facturación, pagos, notas de crédito, comisiones y reportes;
  - factura electrónica **SRI** (ambiente de pruebas).
- **Multiclínica:** cada clínica es un espacio aislado; un médico puede trabajar en su
  consultorio y en una o varias clínicas.
- **Elo (IA):** propone nota clínica con CIE-10, receta y exámenes, y puede **escuchar la
  consulta** y repartirla en los campos de la historia.

## Arquitectura

```
web/      React 18 · TypeScript · Vite · TanStack Query · Zustand · Tailwind   → Vercel
backend/  Laravel · PHP · API REST /api/v1 · Sanctum · PostgreSQL · dompdf    → Railway (Docker)
          Filament: panel de administración interno (no es la app del médico)
```

| Tema | Cómo se resuelve |
|---|---|
| **Aislamiento entre clínicas** | Los modelos clínicos usan el trait `BelongsToWorkspace`. Un *global scope* (`WorkspaceScope`) agrega el filtro de la clínica activa a **toda** consulta, y `ActiveWorkspace` la valida contra la membresía del usuario. `tests/Feature/Security/TenantRouteCoverageTest.php` recorre todas las rutas con identificador. |
| **Permisos** | Middleware por ruta (`permission:records.manage`, `billing.manage`, …). La web solo oculta botones; la autorización está en el servidor. |
| **Dinero** | `bcmath`: los montos viajan como texto. La numeración de facturas usa `lockForUpdate`. |
| **IA (Elo)** | `app/Services/Elo/*`. Gemini responde en JSON con esquema fijo. Cada CIE-10 se valida contra el catálogo (`ResolvesCie10`). Las alergias se comprueban con reglas fijas. **Nunca** viaja nombre, cédula ni id del paciente (hay pruebas que lo verifican). El dictado usa el reconocimiento de voz del navegador, y al backend solo llega texto, sin números de 10 dígitos. |
| **PDF** | `app/Support/PrescriptionLayout.php` estima el contenido y elige el tamaño de letra para que la receta quepa en una hoja. |
| **SRI** | `app/Services/Sri/*`: XML firmado (XAdES) con el certificado `.p12` del emisor y servicios web de recepción y autorización. |

## Ejecutar en local

**Backend** (requiere Docker):

```bash
cd backend
cp .env.example .env
composer install
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
```

**Web:**

```bash
cd web
cp .env.example .env.local   # VITE_API_URL=http://localhost
npm install
npm run dev
```

Elo necesita `GEMINI_API_KEY` en `backend/.env`. Sin ella, todo lo demás funciona.

## Pruebas

```bash
cd backend && ./vendor/bin/sail artisan test   # Pest, contra PostgreSQL real (~1 200 pruebas)
cd web && npx vitest run                       # Vitest + Testing Library (~490 pruebas)
```

## Autor

Mathias Yupa Panchi. Desarrollado con apoyo de asistentes de programación con IA; las
decisiones de producto y arquitectura son del autor.

© 2026 Mathias Yupa Panchi. Todos los derechos reservados. El código se publica para su
revisión; no se concede licencia de uso, copia ni distribución.
