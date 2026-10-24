# Checklist — Dónde deben vivir los Términos y la Política en el producto

> Documento interno. Nada de esto está implementado aún; es el mapa de implementación
> para cuando los textos estén validados legalmente. Ordenado por prioridad.

## 1. Páginas públicas (obligatorio antes de publicar los textos)

- [ ] `https://elosar.site/terminos` y `https://elosar.site/privacidad` como rutas públicas del frontend (`medico-web`), accesibles **sin login**, renderizando los Markdown de `docs/legal/`.
- [ ] Cada página muestra **versión y fecha de vigencia** al inicio.
- [ ] Versiones anteriores accesibles (o al menos archivadas internamente) para poder demostrar qué texto aceptó cada usuario.

## 2. Aceptación en el registro (obligatorio)

Los **tres** flujos de alta deben exigir aceptación explícita (checkbox NO pre-marcado, con enlaces a ambos documentos):

- [ ] Registro de médico (`POST /auth/register` — wizard de auto-registro).
- [ ] Registro por invitación de equipo (`POST /auth/register/staff` — staff **y** médicos invitados, `StaffRegisterPage`).
- [ ] Registro de administrador de clínica (`POST /auth/register/clinic-admin`).

Backend:

- [ ] Guardar evidencia de aceptación en `users`: `terms_accepted_at` (timestamp) + `terms_version` (string). El FormRequest rechaza el alta sin `accepted_terms=true`.
- [ ] Al publicar una **nueva versión** con cambios sustanciales: modal de re-aceptación al iniciar sesión (comparar `terms_version` del usuario con la vigente).

## 3. Footer y accesos permanentes

- [ ] Enlaces "Términos" y "Privacidad" en el pie de la pantalla de **login** y de las pantallas públicas de registro.
- [ ] Enlace en **Ajustes** (sección legal) dentro de la app, visible para cualquier rol.
- [ ] Enlace en el pie de los **correos transaccionales** (invitaciones, confirmaciones/recordatorios de cita) — al menos a la Política de Privacidad.

## 4. Encargo de tratamiento (DPA) — clínicas

- [ ] Al **crear una clínica** (o al aceptar los Términos siendo propietario), dejar constancia de que el propietario acepta el encargo de tratamiento (la Política hace de DPA). Basta un texto junto al checkbox: "Acepto los Términos y el encargo de tratamiento de datos de pacientes descrito en la Política de Privacidad".
- [ ] Si el abogado prefiere un DPA separado, se convierte en tercer documento público y tercer enlace.

## 5. Material para el consentimiento del paciente (recomendado, fase 2)

- [ ] Plantilla imprimible de **consentimiento informado LOPDP** para que el médico la haga firmar al paciente (y al representante legal si es menor) — descargable desde Ajustes. Elosar la provee como ayuda; la obligación es del responsable.
- [ ] (Futuro) Registro digital del consentimiento en la ficha del paciente (`consent_given_at`, alcance, archivo escaneado).

## 6. Operativa interna del titular (no es UI)

- [ ] Completar los campos `[A COMPLETAR]` de ambos documentos (identidad legal, RUC, domicilio, email, planes/pagos, proveedor de email).
- [ ] Confirmar con el abogado: plazo de retención clínica (hoy referencial 10 años vía `LOPDP_RETENTION_YEARS`), mecanismo de transferencia internacional (§8 de la Política), y si corresponde registrar Delegado de Protección de Datos ante la SPDP.
- [ ] Definir y documentar el procedimiento interno de respuesta a brechas (quién notifica a la SPDP y a los responsables en ≤ 5 días).
- [ ] Restringir `CORS_ALLOWED_ORIGINS` en producción (pendiente técnico ya registrado) — coherencia con lo declarado en seguridad.
