# Política de Privacidad — Elosar

**Versión:** BORRADOR v0.1 — [FECHA DE PUBLICACIÓN A COMPLETAR]
**Sitio:** https://elosar.site

Esta Política explica cómo se tratan los datos personales en Elosar, conforme a la **Ley Orgánica de Protección de Datos Personales del Ecuador** (LOPDP, Registro Oficial Suplemento 459 de 26 de mayo de 2021) y su Reglamento. Forma parte de los [Términos y Condiciones](./terminos-y-condiciones.md) y hace las veces de **acuerdo de encargo de tratamiento** entre el profesional/clínica y Elosar.

---

## 1. Responsable de esta Política

- **Titular del servicio:** [A COMPLETAR — razón social o persona natural]
- **RUC:** [A COMPLETAR]
- **Domicilio:** [A COMPLETAR — ciudad, Ecuador]
- **Contacto de privacidad:** [EMAIL A COMPLETAR]
- **Delegado de Protección de Datos (si aplica):** [A COMPLETAR / "No designado — a evaluar según el volumen de tratamiento", ver nota legal §12]

## 2. Los dos roles de Elosar

Elosar trata datos en dos calidades distintas, y esta separación gobierna toda la Política:

| | Datos de **usuarios profesionales** (médicos, personal, propietarios) | Datos de **pacientes** |
|---|---|---|
| ¿Quién decide para qué se usan? | **Elosar** (responsable del tratamiento) | **El médico o la clínica** titular del espacio de trabajo (responsable del tratamiento) |
| ¿Qué hace Elosar? | Gestiona la cuenta y presta el servicio | Actúa como **encargado del tratamiento**: procesa los datos solo por instrucción del profesional, para prestarle el servicio |
| ¿Ante quién ejerce derechos el titular? | Ante Elosar | Ante su médico o clínica; Elosar asiste al responsable para atender esas solicitudes |

Los pacientes no tienen cuenta ni acceso directo a la Plataforma; su relación es con su médico o clínica.

## 3. Qué datos se tratan

**a) De los usuarios profesionales (Elosar como responsable):**

- Identificación y contacto: nombre, cédula, correo, teléfono, ciudad.
- Credenciales profesionales: registro/licencia MSP, código ACESS, registro SENESCYT, especialidades.
- Imagen de firma manuscrita y fotografía de perfil (si el usuario las carga).
- Certificado de firma electrónica (.p12) y su contraseña, cargados voluntariamente para la facturación electrónica SRI. El archivo se guarda en almacenamiento privado y la contraseña **cifrada**; nunca se muestran ni se reutilizan para otro fin.
- Datos de configuración fiscal del espacio de trabajo (RUC, razón social, direcciones, establecimiento).
- Datos técnicos de uso: tokens de sesión y registros de actividad.

**b) De los pacientes (Elosar como encargado, por cuenta del profesional):**

- Identificación y contacto: nombre, cédula, fecha de nacimiento, sexo, teléfono, correo, ciudad, contacto de emergencia, datos del seguro.
- **Datos de salud (categoría especial / dato sensible bajo la LOPDP):** historia clínica, motivo de consulta, signos vitales, diagnósticos CIE-10, prescripciones, alergias, antecedentes personales y familiares, vacunación, exploraciones, órdenes y resultados de laboratorio e imagen, certificados médicos, y los archivos adjuntos que el profesional cargue.
- Datos de facturación de la atención: facturas, pagos, notas de crédito.

**c) Registros de acceso (trazabilidad LOPDP):** la Plataforma registra **quién accede a qué historia clínica y cuándo** (visualización, descarga de PDF, exportaciones). Estos registros protegen al paciente y permiten al responsable demostrar el uso legítimo de la información.

## 4. Para qué y con qué base legal

| Tratamiento | Base legal (LOPDP) |
|---|---|
| Crear y administrar la cuenta profesional, prestar el servicio | Ejecución del contrato (estos Términos) |
| Tratar datos de pacientes por cuenta del profesional | Encargo de tratamiento; la base legal frente al paciente la aporta el **responsable**: consentimiento explícito del paciente para datos de salud y/o tratamiento por profesionales de la salud sujetos a secreto profesional, conforme al capítulo de categorías especiales de la LOPDP (arts. 25 y siguientes) |
| Enviar correos transaccionales (confirmaciones y recordatorios de cita, saludos) a pacientes | Instrucción del responsable, que debe contar con la base legal para ese contacto |
| Registros de acceso y seguridad | Obligación legal y responsabilidad proactiva (LOPDP) |
| Soporte y comunicaciones del servicio a usuarios | Ejecución del contrato / interés legítimo |
| Facturación electrónica ante el SRI | Obligación legal del usuario emisor |

Elosar **no vende datos**, no hace publicidad con datos de pacientes y no usa los datos clínicos para fines propios distintos de prestar el servicio.

## 5. Dato de salud: medidas reforzadas

Por tratarse de datos sensibles, la Plataforma aplica, entre otras, estas medidas:

- **Aislamiento multi-clínica:** cada espacio de trabajo está separado; el sistema impide técnicamente el acceso a datos de otra consulta o clínica.
- **Permisos por rol dentro de la clínica:** recepción y caja no acceden a la historia clínica; los reportes clínicos con identificación de pacientes solo llegan a usuarios con rol médico; un médico empleado no accede a la información administrativa y financiera de la clínica.
- Cifrado del tráfico (HTTPS/TLS) y cifrado de credenciales sensibles almacenadas (p. ej., contraseña del certificado de firma).
- Registro de accesos a historias clínicas y documentos clínicos (§3.c).
- Respaldos periódicos de la base de datos.
- Contraseñas almacenadas con hash seguro; límites contra intentos de acceso por fuerza bruta.

## 6. Derechos de los titulares

La LOPDP reconoce, entre otros, los derechos de **acceso, rectificación y actualización, eliminación, oposición, portabilidad y suspensión del tratamiento**, y el derecho a no ser objeto de decisiones basadas únicamente en tratamientos automatizados. La Plataforma no toma decisiones automatizadas con efectos jurídicos sobre pacientes ni usuarios.

- **Pacientes:** deben dirigirse a su médico o clínica (el responsable). Elosar proporciona al responsable las herramientas para atender esas solicitudes: consulta y exportación de la información, corrección de datos, y **anonimización** del paciente cuando proceda la eliminación pero exista obligación de conservar la historia clínica (se desvinculan los datos identificativos preservando el registro clínico exigido por ley).
- **Usuarios profesionales:** pueden ejercer sus derechos escribiendo a [EMAIL A COMPLETAR]. Plazo general de respuesta: **quince (15) días**.
- Ambos pueden presentar reclamos ante la **Superintendencia de Protección de Datos Personales** del Ecuador si consideran vulnerados sus derechos.

## 7. Encargados y sub-encargados (terceros que procesan datos)

Para operar, Elosar utiliza los siguientes proveedores de infraestructura, que actúan como sub-encargados respecto de los datos de pacientes y como encargados respecto de los datos de usuarios:

| Proveedor | Función | Datos que toca | Ubicación |
|---|---|---|---|
| **Railway** | Alojamiento del servidor de la aplicación y de la base de datos PostgreSQL; almacenamiento de archivos (adjuntos clínicos, firmas, certificados) | Todos los datos del sistema | Estados Unidos |
| **Vercel** | Alojamiento de la aplicación web (interfaz) | Datos técnicos de navegación; los datos de negocio viajan cifrados hacia el servidor | Estados Unidos |
| **Google (API Gemini)** | Asistente de IA opcional | Solo los fragmentos de datos del espacio de trabajo consultados cuando un médico usa el asistente | Estados Unidos |
| **[PROVEEDOR DE EMAIL A COMPLETAR]** (envío SMTP) | Correos transaccionales (invitaciones de equipo, confirmaciones y recordatorios de cita) | Nombre y correo del destinatario, datos de la cita | [A COMPLETAR] |

Los enlaces de WhatsApp de la Plataforma son del tipo "click-to-chat": abren la aplicación de WhatsApp del propio usuario con un mensaje pre-escrito; Elosar **no** envía datos a WhatsApp ni opera su API.

Elosar exige a estos proveedores condiciones de confidencialidad y seguridad, y mantendrá esta lista actualizada; los cambios sustanciales de sub-encargados se comunicarán a los usuarios.

## 8. Transferencias internacionales

Los servidores de los proveedores listados en §7 están fuera del Ecuador (Estados Unidos). En consecuencia, existe **transferencia internacional de datos**, amparada en los mecanismos previstos por la LOPDP y su Reglamento (garantías adecuadas de carácter contractual con los proveedores y, en lo pertinente, el consentimiento informado del usuario al aceptar esta Política). El usuario, como responsable de los datos de sus pacientes, autoriza expresamente a Elosar el uso de estos sub-encargados internacionales para la prestación del servicio.

> [NOTA PARA REVISIÓN LEGAL: validar el mecanismo específico aplicable (cláusulas tipo aprobadas por la Superintendencia, autorización, o consentimiento) según el estado normativo vigente.]

## 9. Plazos de conservación

| Categoría | Plazo | Fundamento |
|---|---|---|
| Historia clínica y datos de salud | **10 años desde la última atención** [CIFRA DE REFERENCIA — A CONFIRMAR con asesoría legal frente a la normativa MSP de manejo y archivo de la historia clínica] | Normativa sanitaria; tras la baja del usuario se conserva o anonimiza según §6 |
| Comprobantes electrónicos (facturas, notas de crédito) y su información | **7 años** | Normativa tributaria (SRI) |
| Datos de la cuenta profesional | Mientras la cuenta esté activa + plazos de prescripción legales | Contrato / obligación legal |
| Registros de acceso a historias clínicas | [A DEFINIR — recomendado ≥ 5 años] | Responsabilidad proactiva LOPDP |
| Respaldos técnicos | Ciclo de rotación limitado | Seguridad y continuidad |

Vencidos los plazos, los datos se eliminan o anonimizan de forma irreversible.

## 10. Violaciones de seguridad

Si ocurriera una vulneración de seguridad que afecte datos personales, Elosar la notificará a la **Superintendencia de Protección de Datos Personales** y —cuando exista riesgo para los titulares— a los responsables y titulares afectados, dentro de los plazos de la LOPDP y su Reglamento (hasta **cinco (5) días** desde el conocimiento del hecho), informando el alcance, los datos afectados y las medidas adoptadas. Como encargado, Elosar notificará sin dilación al médico/clínica responsable para que este cumpla sus propias obligaciones de notificación.

## 11. Pacientes menores de edad

La Plataforma registra atenciones pediátricas. El tratamiento de datos de niñas, niños y adolescentes exige el **consentimiento del representante legal**, que debe ser obtenido y conservado por el médico o clínica responsable antes de registrar los datos. Elosar no recolecta datos de menores directamente ni permite cuentas de menores de edad.

## 12. Notas de cumplimiento del titular del servicio

- **Registro Nacional / obligaciones ante la Superintendencia:** [A COMPLETAR según requerimientos vigentes de la SPDP — p. ej., registro del responsable y del Delegado de Protección de Datos si el volumen de tratamiento de datos sensibles lo exige.]
- Esta Política se revisará al menos una vez al año y cuando cambien los tratamientos, proveedores o la normativa.

## 13. Cambios a esta Política

Publicaremos las modificaciones en https://elosar.site/privacidad indicando la versión y fecha. Los cambios sustanciales se comunicarán a los usuarios por la Plataforma o por correo con antelación razonable; cuando un cambio amplíe los tratamientos, se solicitará nueva aceptación.

## 14. Contacto

Privacidad y protección de datos: [EMAIL A COMPLETAR]
Autoridad de control: Superintendencia de Protección de Datos Personales — https://spdp.gob.ec

---

> **Borrador para validación de un abogado ecuatoriano especializado en protección de datos antes de publicar.**
