# Facturación electrónica SRI — documentación para aprender cómo se hizo

> **Para quién es este documento:** para alguien que **no sabe casi nada** del tema y quiere
> entender, paso a paso y sin dar nada por sentado, qué es la facturación electrónica del SRI,
> cómo funciona por dentro, y cómo se construyó en este proyecto (`medico-backend`).
>
> Cada concepto se explica desde cero, con analogías, y luego se conecta con el archivo de
> código donde vive. Al final hay un glosario.
>
> **Última actualización:** 2026-07-21.

---

## Índice

1. [¿Qué problema resuelve todo esto?](#1-qué-problema-resuelve-todo-esto)
2. [La idea más importante: una factura electrónica NO es un PDF](#2-la-idea-más-importante-una-factura-electrónica-no-es-un-pdf)
3. [¿Qué es un XML? (desde cero)](#3-qué-es-un-xml-desde-cero)
4. [Cómo funciona la plataforma del SRI (el "esquema offline")](#4-cómo-funciona-la-plataforma-del-sri-el-esquema-offline)
5. [La clave de acceso: el DNI de cada factura](#5-la-clave-de-acceso-el-dni-de-cada-factura)
6. [El XML de la factura por dentro](#6-el-xml-de-la-factura-por-dentro)
7. [Validar contra el XSD: revisar antes de enviar](#7-validar-contra-el-xsd-revisar-antes-de-enviar)
8. [El certificado digital `.p12`: la identidad del médico](#8-el-certificado-digital-p12-la-identidad-del-médico)
9. [La firma digital y XAdES-BES: el corazón del asunto](#9-la-firma-digital-y-xades-bes-el-corazón-del-asunto)
10. [SOAP: cómo se le habla al SRI](#10-soap-cómo-se-le-habla-al-sri)
11. [El flujo completo, orquestado](#11-el-flujo-completo-orquestado)
12. [El RIDE: el PDF para el paciente](#12-el-ride-el-pdf-para-el-paciente)
13. [Qué se verificó contra el SRI real](#13-qué-se-verificó-contra-el-sri-real)
14. [Cómo se investigó la competencia (Orpheus)](#14-cómo-se-investigó-la-competencia-orpheus)
15. [Mapa: concepto → archivo de código](#15-mapa-concepto--archivo-de-código)
16. [Glosario](#16-glosario)

---

## 1. ¿Qué problema resuelve todo esto?

En Ecuador, el **SRI** (Servicio de Rentas Internas) es la institución que controla los impuestos.
Desde hace años, las facturas ya no pueden ser solo un papel: tienen que ser **electrónicas** y
estar **autorizadas por el SRI** para tener validez legal (para que cuenten como gasto, para el IVA,
para las declaraciones, etc.).

Entonces, cuando un médico atiende a un paciente y le cobra, la factura que emite tiene que:

1. Generarse en un formato exacto que el SRI define.
2. Firmarse digitalmente (como una firma, pero criptográfica).
3. Enviarse al SRI para que la **autorice**.

Nuestro sistema (`medico-backend`) hace esas tres cosas automáticamente. Este documento explica cómo.

---

## 2. La idea más importante: una factura electrónica NO es un PDF

Si te quedas con **una sola idea** de todo el documento, que sea esta:

> **La factura electrónica de verdad es un archivo de texto llamado XML. El PDF bonito que ve el
> paciente es solo una "foto" de ese archivo.**

Mucha gente cree que la factura es el papel/PDF con el logo y los datos. **No.** Para el SRI y para
la ley, el documento real es el **XML** (un archivo de texto con una estructura específica). El PDF
—que en Ecuador se llama **RIDE**— es solamente una **representación imprimible** para que el humano
lo pueda leer. Los dos representan la misma factura, pero el que tiene valor legal es el XML.

Analogía: piensa en el XML como el **acta oficial firmada y sellada** que se guarda en el archivo, y
el RIDE como la **fotocopia** que te llevas a casa. La fotocopia sirve para leer, pero el original es
el acta.

---

## 3. ¿Qué es un XML? (desde cero)

**XML** significa *eXtensible Markup Language* ("lenguaje de marcado extensible"). Suena complicado,
pero es simple: es una forma de **guardar información en texto, organizada con etiquetas**.

Si alguna vez viste código de una página web (HTML), es casi lo mismo. Se usan **etiquetas** que
abren y cierran, con datos adentro.

Ejemplo. Imagina que quieres guardar los datos de un paciente. En XML se vería así:

```xml
<paciente>
  <nombre>María Pérez</nombre>
  <cedula>1712345678</cedula>
  <edad>34</edad>
</paciente>
```

Fíjate en el patrón:

- `<nombre>` **abre** una etiqueta.
- `María Pérez` es el **dato**.
- `</nombre>` **cierra** la etiqueta (la barra `/` indica cierre).
- Las etiquetas pueden estar **anidadas** (unas dentro de otras): `<paciente>` contiene a `<nombre>`,
  `<cedula>` y `<edad>`.

¿Por qué se usa XML para las facturas? Porque:

1. **Es texto plano** → cualquier computadora lo puede leer, sin importar la marca o el programa.
2. **Es estructurado** → el SRI puede pedir "aquí va el RUC, aquí el total, aquí el IVA" y siempre
   está en el mismo lugar.
3. **Es verificable** → se puede validar que tenga exactamente la forma esperada (ver sección 7).

Una factura del SRI es, en el fondo, un XML como el de arriba pero mucho más grande, con los datos
del emisor, del comprador, de cada ítem y de los impuestos.

---

## 4. Cómo funciona la plataforma del SRI (el "esquema offline")

Aquí hay otra idea que sorprende a casi todos:

> **El SRI no tiene un formulario donde tú "llenas" una factura. Tú generas la factura completa por
> tu cuenta, y el SRI solo la revisa y la aprueba.**

Este modelo se llama **"esquema offline"**. Funciona así:

```
   TU SISTEMA                         EL SRI
   ─────────                          ──────
1. Genera el XML de la factura
2. Lo firma digitalmente
3. Se lo envía  ───────────────────▶  4. Lo recibe y revisa
                                      5. Si está bien: "RECIBIDA"
6. Le pregunta:               ◀────── 
   "¿ya autorizaste esta clave?"
                              ───────▶ 7. Responde: "AUTORIZADO"
8. Guarda el número de
   autorización y genera el RIDE
```

El SRI expone **dos servicios** distintos para esto (piénsalos como dos "ventanillas"):

| Ventanilla | Para qué sirve | Respuestas posibles |
|---|---|---|
| **Recepción** | Recibe el XML firmado y hace una primera revisión | `RECIBIDA` / `DEVUELTA` |
| **Autorización** | Confirma si la factura quedó legalmente autorizada | `AUTORIZADO` / `NO AUTORIZADO` / `EN PROCESAMIENTO` |

**¿Por qué son dos pasos y no uno?** Porque el proceso es **asíncrono** (no es instantáneo). Tú
envías la factura y el SRI la pone en una cola. Un momento después le preguntas si ya la procesó.
Es como dejar un trámite y volver a preguntar "¿ya está listo?". Por eso el sistema tiene un
mecanismo de reintentos (ver sección 11).

Además, el SRI tiene **dos ambientes** (dos versiones de su plataforma):

- **Pruebas** (`celcer.sri.gob.ec`): para practicar. Autoriza facturas, pero **NO tienen validez
  tributaria**. Es un simulador oficial.
- **Producción** (`cel.sri.gob.ec`): el de verdad. Aquí las facturas son documentos legales.

Siempre se empieza en **pruebas** y, cuando todo funciona, se pasa a **producción**.

> **En el código:** los dos servicios SOAP y los dos ambientes viven en
> [`app/Services/Sri/SriSoapClient.php`](../../app/Services/Sri/SriSoapClient.php).

---

## 5. La clave de acceso: el DNI de cada factura

Cada factura electrónica tiene una **clave de acceso**: un número de **49 dígitos** que la
identifica de forma única en todo el Ecuador. Es como la cédula de identidad de la factura.

Lo interesante es que **no es un número al azar**: se **construye** juntando datos de la propia
factura. Así se arma:

| Parte | Dígitos | Ejemplo | Qué es |
|---|---|---|---|
| Fecha de emisión | 8 | `21072026` | día, mes, año (ddmmaaaa) |
| Tipo de comprobante | 2 | `01` | `01` = factura, `04` = nota de crédito |
| RUC del emisor | 13 | `1790011674001` | el RUC del médico/clínica |
| Ambiente | 1 | `1` | `1` = pruebas, `2` = producción |
| Serie | 6 | `001001` | establecimiento (3) + punto de emisión (3) |
| Secuencial | 9 | `000000001` | el número de la factura |
| Código numérico | 8 | `48392017` | un número aleatorio de relleno |
| Tipo de emisión | 1 | `1` | `1` = normal |
| **Dígito verificador** | 1 | `7` | se calcula (ver abajo) |

Total: 48 dígitos de datos + 1 dígito verificador = **49**.

### El dígito verificador (módulo 11)

El último dígito no es un dato: es un **número de control** que se **calcula** a partir de los otros
48. Sirve para detectar errores de tipeo.

La técnica se llama **módulo 11**. En palabras simples: se recorren los 48 dígitos de derecha a
izquierda, cada uno se multiplica por un "peso" que va rotando (2, 3, 4, 5, 6, 7, y vuelve a 2...),
se suman todos los resultados, y con esa suma se obtiene el dígito final.

¿Para qué? Para que la clave sea **autoverificable**: si alguien teclea mal un dígito, el número de
control ya no cuadra, y el SRI lo detecta **sin consultar su base de datos**. Es el mismo truco que
usan los números de tarjeta de crédito y las cédulas.

> **En el código:** toda esta construcción está en
> [`app/Services/Sri/SriClaveAccesoBuilder.php`](../../app/Services/Sri/SriClaveAccesoBuilder.php),
> incluido el cálculo del módulo 11 (método `calculateMod11`).

---

## 6. El XML de la factura por dentro

Ahora que sabes qué es un XML (sección 3) y qué es la clave de acceso (sección 5), veamos cómo se
ve una factura del SRI. Su estructura general es:

```xml
<factura id="comprobante" version="2.1.0">
  <infoTributaria>   <!-- QUIÉN EMITE: RUC, razón social, clave de acceso, establecimiento -->
  <infoFactura>      <!-- A QUIÉN: datos del comprador, totales, IVA, forma de pago -->
  <detalles>         <!-- QUÉ: cada línea de la factura (servicio, cantidad, precio) -->
  <infoAdicional>    <!-- EXTRA: email y dirección del paciente -->
</factura>
```

Dos detalles que parecen menores pero son críticos:

1. **`id="comprobante"` es obligatorio.** Es una "etiqueta con nombre" que le pone identidad a la
   raíz del documento. ¿Por qué importa? Porque **la firma digital (sección 9) necesita apuntar a
   algo**, y apunta justo a ese `id`. Sin él, no hay a qué "amarrar" la firma.

2. **El IVA en salud.** En Ecuador **los servicios de salud están exentos de IVA** (tarifa 0%). Por
   eso, en el XML de una factura médica, el impuesto va con código de porcentaje `0`. El sistema
   traduce automáticamente la tarifa a los códigos que el SRI espera.

También hay una **limitación asumida a propósito**: el SRI solo entiende **descuentos por cada ítem**,
no un descuento global sobre el total de la factura. Si una factura trae un descuento global, el
generador de XML **lanza un error a propósito** en vez de emitir un documento cuyos totales no
cuadrarían. Preferimos fallar de forma visible antes que mandar algo mal hecho al SRI.

> **En el código:** el armado del XML está en
> [`app/Services/Sri/SriXmlBuilder.php`](../../app/Services/Sri/SriXmlBuilder.php).

---

## 7. Validar contra el XSD: revisar antes de enviar

Un **XSD** es un archivo que define **las reglas de forma** de un XML: qué etiquetas deben existir,
en qué orden, cuáles son obligatorias, qué tipo de dato lleva cada una. Es como una **plantilla de
requisitos**.

El SRI publica sus XSD oficiales (por ejemplo, `factura_V2.1.0.xsd`). Nosotros los guardamos dentro
del proyecto (en `resources/sri/xsd/`) y, **antes de enviar** cualquier factura, la comparamos
contra esa plantilla.

**¿Por qué molestarse en revisar localmente si el SRI también revisa?** Por una razón muy práctica:
si mandas un XML mal formado, el SRI responde `DEVUELTA` con un mensaje **genérico e inútil** (algo
como "el comprobante tiene errores"). En cambio, validar localmente te dice **exactamente** qué está
mal y en qué línea ("falta la etiqueta X en la línea 47"). Ahorra horas de adivinar a ciegas.

> **En el código:** la validación está en
> [`app/Services/Sri/SriXsdValidator.php`](../../app/Services/Sri/SriXsdValidator.php).

---

## 8. El certificado digital `.p12`: la identidad del médico

Para firmar una factura, el médico necesita un **certificado digital**, que viene en un archivo con
extensión **`.p12`** (o `.pfx`). Este es el archivo que el médico compra a una entidad autorizada
(por ejemplo, el Banco Central del Ecuador, ~$25).

### ¿Qué hay dentro de un `.p12`?

El `.p12` usa un formato estándar llamado **PKCS#12**. Es, en esencia, una **caja fuerte protegida
con contraseña** que guarda **dos cosas juntas**:

| Contenido | Qué es | Analogía |
|---|---|---|
| **Certificado (parte pública)** | Dice "yo soy el Dr. Pérez, RUC 179…, y una entidad de confianza lo garantiza" | Tu cédula: la puedes mostrar a todos |
| **Llave privada (secreto)** | El secreto matemático con el que se firma | Tu firma de puño y letra: solo tú la puedes hacer |

La **llave privada** es lo más importante y lo más secreto: es lo que permite firmar. Nadie más debe
tenerla. Por eso el archivo va protegido con contraseña.

### Cómo lo maneja nuestro sistema (con cuidado)

- El médico sube el `.p12` y su contraseña desde la pantalla de **Ajustes → Facturación SRI**.
- El archivo se guarda en un **disco privado** del servidor, **nunca** en uno público → jamás es
  accesible por una URL.
- La contraseña se guarda **cifrada** en la base de datos.
- Ambos datos están marcados como "ocultos" en el sistema → **nunca** salen en una respuesta de la
  API.
- Solo el **dueño del espacio de trabajo** puede subirlo.

> **En el código:** el archivo se carga y se abre en
> [`app/Services/Sri/SriCertificateLoader.php`](../../app/Services/Sri/SriCertificateLoader.php)
> (usa la función `openssl_pkcs12_read` para extraer el certificado y la llave privada).

---

## 9. La firma digital y XAdES-BES: el corazón del asunto

Esta es la parte más difícil de todo el proyecto, y donde fallan casi todas las implementaciones
caseras. Vamos por partes.

### ¿Qué es "firmar digitalmente"?

Firmar digitalmente un documento **no es dibujar una firma**. Es un proceso matemático que garantiza
dos cosas:

1. **Quién** firmó (identidad, gracias al certificado).
2. Que el documento **no se modificó** después de firmarlo (integridad).

Funciona con dos conceptos:

- **Hash (huella digital):** es un cálculo que convierte cualquier documento en un número corto y
  único. Si cambias **una sola letra** del documento, la huella cambia por completo. Es como sacarle
  una huella dactilar al texto.
- **Firma con la llave privada:** esa huella se "sella" usando la llave privada del certificado. Solo
  el dueño de la llave pudo hacer ese sello, y cualquiera puede verificarlo con la parte pública.

El resultado: si alguien altera la factura después de firmada, la huella ya no coincide y la firma
queda inválida. El SRI recalcula todo por su lado para comprobarlo.

### ¿Qué es XAdES-BES?

**XAdES-BES** es el **estándar de firma** que el SRI exige. Es un formato europeo (de la ETSI) que
define **cómo** debe verse la firma dentro del XML. "Enveloped" (envuelta) significa que la firma
vive **dentro del mismo documento** que firma.

El SRI pide algoritmos específicos (SHA-1 para las huellas, RSA para el sello). Son algoritmos
"viejos", pero son los que el SRI exige, así que no hay opción.

### El problema del huevo y la gallina, y la solución en dos fases

Aquí está la parte sofisticada. La firma va **dentro** del documento que firma. Entonces surge una
paradoja: **¿cómo firmas algo que te incluye a ti mismo?** Si calculas la huella del documento
completo, esa huella incluiría a la firma... que todavía no existe.

Y hay un segundo problema, aún más sutil, llamado **canonicalización**:

> **Canonicalización (C14N):** el mismo XML se puede escribir de mil maneras distintas (con más o
> menos espacios, con los atributos en otro orden, con comillas simples o dobles) y **seguir siendo el
> mismo documento**. Pero la huella digital es súper sensible: si cambia **un solo byte**, la huella
> cambia. Entonces, antes de sacar la huella, hay que "normalizar" el XML a **una forma única y
> byte-exacta**. Eso es la canonicalización.

El sistema resuelve las dos cosas firmando en **dos fases**:

1. **Fase 1:** se saca la huella del documento **antes** de que exista la firma. Esto equivale
   exactamente a lo que el SRI hace al validar: quita la firma y recalcula.
2. **Fase 2:** se inyecta el "esqueleto" de la firma en el documento y **recién ahí** se sacan las
   huellas de las otras partes, **tal como quedan dentro del documento final**.

¿Por qué la fase 2 es tan importante? Porque la canonicalización **hereda información de los elementos
que están por encima** (los "ancestros"). La huella de un pedazo aislado **no** da lo mismo que la
huella de ese pedazo cuando está anidado dentro del documento completo. El SRI recalcula sobre el
documento final; si nosotros calculamos sobre pedazos sueltos, **las huellas no coinciden y la firma
se rechaza**. Esta es, literalmente, la causa número uno por la que los firmadores caseros fallan.

> **En el código:** toda la firma está en
> [`app/Services/Sri/SriXmlSigner.php`](../../app/Services/Sri/SriXmlSigner.php) (275 líneas). Los
> comentarios del archivo explican las dos fases en detalle. Hay además un test que **replica la
> validación del SRI** para comprobar que la firma es correcta:
> `tests/Unit/SriXmlSignerTest.php`.

---

## 10. SOAP: cómo se le habla al SRI

**SOAP** es simplemente **una forma en que dos computadoras se comunican por internet**. Es un
protocolo (un "idioma" con reglas) que existe desde los 2000. Hoy casi todo usa algo más moderno
(REST), pero **el SRI todavía usa SOAP**, así que hay que hablarle en su idioma.

Analogía: es como si el SRI solo contestara el **teléfono fijo** y no WhatsApp. No importa que sea
viejo; si quieres hablar con él, llamas por teléfono fijo.

Con SOAP, nuestro sistema:

1. Llama a la ventanilla de **recepción** y le pasa el XML firmado → recibe `RECIBIDA` o `DEVUELTA`.
2. Llama a la ventanilla de **autorización** con la clave de acceso → recibe `AUTORIZADO`, etc.

Hay dos situaciones que el sistema maneja con cuidado:

- Si el SRI responde que la **clave ya estaba registrada** (mensaje `43`), no es un error: significa
  que ya la había recibido antes, así que pasamos directo a preguntar por la autorización.
- Si al preguntar por la autorización el SRI dice que **aún no encuentra** la clave, no es una falla:
  todavía no la procesó. Se marca "en procesamiento" y se reintenta más tarde.

> **En el código:**
> [`app/Services/Sri/SriSoapClient.php`](../../app/Services/Sri/SriSoapClient.php).

---

## 11. El flujo completo, orquestado

Juntando todo, el proceso completo para autorizar una factura tiene **7 pasos**, cada uno en su
propia clase (cada clase hace una sola cosa):

```
1. Clave de acceso   →  SriClaveAccesoBuilder   (49 dígitos + módulo 11)
2. Armar el XML      →  SriXmlBuilder           (estructura oficial)
3. Validar XSD       →  SriXsdValidator         (revisar antes de enviar)
4. Cargar el .p12    →  SriCertificateLoader    (abrir la caja fuerte)
5. Firmar XAdES-BES  →  SriXmlSigner            (las dos fases)
6. Enviar (SOAP)     →  SriSoapClient           (recepción)
7. Autorizar         →  SriSoapClient           (autorización)
```

Quien coordina los 7 pasos es el **orquestador**: `SriService`. Y quien lo dispara todo, de forma
automática, es un **trabajo en segundo plano** (`AuthorizeSriVoucherJob`).

**¿Qué significa "en segundo plano"?** Que el médico crea la factura y **no tiene que esperar** a que
el SRI responda. La factura se guarda al instante, y el envío al SRI ocurre por detrás, sin bloquear
la pantalla. Si el SRI está lento o rechaza algo, el trabajo se **reintenta** solo.

### Los estados de una factura

Cada factura guarda en qué punto va:

| Estado | Qué significa |
|---|---|
| `pending` (Pendiente) | Aún no autorizada; en cola o en procesamiento |
| `authorized` (Autorizada) | El SRI la aprobó; ya es legal. Se guarda el número de autorización |
| `rejected` (Rechazada) | El SRI la rechazó; se guarda el mensaje exacto para poder corregir |

Un detalle importante de diseño: el sistema es **idempotente**. Significa que si el trabajo se
reintenta, **no** genera una factura nueva ni una clave nueva: reusa las que ya calculó. Así, reenviar
es seguro y nunca duplica.

> **En el código:** el orquestador es
> [`app/Services/Sri/SriService.php`](../../app/Services/Sri/SriService.php); el disparo automático
> ocurre en `InvoiceService::create()` (con `afterCommit()`, es decir, solo después de que la factura
> se guardó de verdad en la base de datos).

---

## 12. El RIDE: el PDF para el paciente

Como vimos en la sección 2, el documento legal es el XML, pero al paciente no le puedes entregar un
archivo XML (no lo entendería). Para eso existe el **RIDE** (*Representación Impresa del Documento
Electrónico*): el **PDF legible**, con el logo, los datos, el detalle, y un **código QR**.

Ese QR apunta a una página del SRI donde cualquiera puede **verificar** que la factura es real y está
autorizada. El sistema genera el QR y arma el RIDE una vez que la factura queda autorizada.

---

## 13. Qué se verificó contra el SRI real

Este proyecto **no** se quedó en la teoría. El **12 de junio de 2026** se probó el flujo completo
contra `celcer.sri.gob.ec` — el ambiente de **pruebas real** del SRI, no un simulador propio.

Resultado:

- ✅ El XML pasó la validación contra el XSD oficial.
- ✅ La clave de acceso fue aceptada.
- ✅ La comunicación SOAP funcionó (recepción y autorización).
- ✅ La **firma XAdES-BES fue RECIBIDA por el SRI**.

Que la firma fuera **RECIBIDA** es lo más importante: significa que el SRI la leyó, recalculó las
huellas por su cuenta y **cuadraron**. Es decir, la parte difícil (la firma) está correcta.

El único rechazo fue el **error 39: cadena de confianza**, porque el certificado usado en la prueba
era **autofirmado** (generado por nosotros), no emitido por una entidad acreditada. Traducido:
*"tu firma está perfecta, pero no sé quién eres"*.

**Conclusión:** con un `.p12` real de una entidad acreditada, el sistema debería autorizar
**sin cambiar una línea de código**.

Para verificar todo esto de forma guiada existe un comando de diagnóstico:

```bash
php artisan sri:check {workspace_id} --send
```

Va revisando paso por paso (clave, XSD, firma, conexión) y hace un envío real al ambiente de pruebas.

---

## 14. Cómo se investigó la competencia (Orpheus)

Parte del trabajo fue estudiar cómo lo hace un competidor real: **Orpheus**
(`consultorios.orpheus.com.ec`), un sistema médico ecuatoriano. Se revisó en vivo (2026-07-20) usando
una cuenta real de un médico ginecólogo.

**Qué se aprendió:**

1. **El motor del SRI es idéntico al nuestro** (y tiene que serlo, por ley). En el módulo de
   facturación de Orpheus se ven los mismos estados del SRI: **AUTORIZADAS / EN PROCESO / RECHAZADAS**
   — que son exactamente nuestros estados `authorized` / `pending` / `rejected`. También usa el mismo
   concepto de **establecimiento** (varios locales) y las mismas **formas de pago** del SRI.

2. **La diferencia es de negocio, no técnica.** Orpheus vende la facturación electrónica y la firma
   como **dos complementos de pago aparte** (no incluidos):

   - **Facturación electrónica** (por volumen anual): desde $40+IVA (200 comprobantes) hasta
     $250+IVA (ilimitados).
   - **Firma electrónica** (la revenden y tramitan ellos): $20+IVA (1 año) hasta $67+IVA (5 años).

3. **Orpheus le tramita la firma al médico.** En vez de que el doctor consiga su `.p12` por su cuenta,
   Orpheus lo hace por él y lo cobra. Esto elimina la fricción más grande del proceso (que el médico
   no sepa qué firma comprar) y les da un ingreso extra.

4. **La firma la usan para más que facturas:** también para firmar **recetas y certificados médicos**
   con validez legal. Eso es una posible mejora futura para nosotros: reusar el mismo `.p12` del SRI
   para firmar digitalmente los PDF clínicos (hoy usamos una firma-imagen para las recetas).

---

## 15. Mapa: concepto → archivo de código

Tabla de referencia rápida para saltar del concepto al código.

| Concepto (sección) | Archivo |
|---|---|
| Clave de acceso, módulo 11 (§5) | `app/Services/Sri/SriClaveAccesoBuilder.php` |
| Armado del XML (§6) | `app/Services/Sri/SriXmlBuilder.php` |
| Validación XSD (§7) | `app/Services/Sri/SriXsdValidator.php` |
| Esquemas oficiales del SRI (§7) | `resources/sri/xsd/*.xsd` |
| Certificado `.p12` (§8) | `app/Services/Sri/SriCertificateLoader.php` |
| Firma XAdES-BES (§9) | `app/Services/Sri/SriXmlSigner.php` |
| Comunicación SOAP y ambientes (§4, §10) | `app/Services/Sri/SriSoapClient.php` |
| Orquestador de los 7 pasos (§11) | `app/Services/Sri/SriService.php` |
| Disparo automático al facturar (§11) | `app/Services/InvoiceService.php` |
| Trabajo en segundo plano + reintentos (§11) | `app/Jobs/AuthorizeSriVoucherJob.php` |
| Comando de diagnóstico (§13) | `app/Console/Commands/SriCheckCommand.php` |
| Configuración fiscal del workspace | `app/Models/Workspace.php` |
| Subida del certificado desde la app | `app/Http/Controllers/Api/SettingsController.php` |

---

## 16. Glosario

- **SRI:** Servicio de Rentas Internas. La entidad tributaria del Ecuador.
- **Factura electrónica:** el documento de venta en formato XML, autorizado por el SRI.
- **XML:** archivo de texto estructurado con etiquetas. El formato del documento legal.
- **RIDE:** *Representación Impresa del Documento Electrónico*. El PDF legible para el paciente.
- **Clave de acceso:** número único de 49 dígitos que identifica la factura.
- **Módulo 11:** técnica para calcular un dígito de control que detecta errores.
- **XSD:** archivo que define las reglas de forma que un XML debe cumplir.
- **Esquema offline:** el modelo del SRI donde tú generas y firmas el documento, y el SRI solo lo
  autoriza.
- **Ambiente:** la versión de la plataforma del SRI. **Pruebas** (sin validez) o **Producción** (real).
- **Certificado digital / `.p12` / PKCS#12:** archivo protegido con contraseña que contiene la
  identidad del firmante y su llave privada.
- **Llave privada:** el secreto matemático con el que se firma. Nunca se comparte.
- **Hash / huella digital:** número corto y único que representa un documento; cambia si el documento
  cambia.
- **Firma digital:** proceso matemático que garantiza quién firmó y que el documento no se alteró.
- **XAdES-BES:** el estándar de firma XML que exige el SRI.
- **Enveloped (envuelta):** la firma vive dentro del mismo documento que firma.
- **Canonicalización / C14N:** normalizar un XML a una forma byte-exacta antes de sacarle la huella.
- **SOAP:** protocolo (idioma) de comunicación entre computadoras que usa el SRI.
- **RECIBIDA / DEVUELTA:** respuestas de la ventanilla de recepción del SRI.
- **AUTORIZADO / NO AUTORIZADO / EN PROCESAMIENTO:** respuestas de la ventanilla de autorización.
- **Idempotente:** una operación que se puede repetir sin causar efectos duplicados.
- **Establecimiento:** cada local físico desde donde se factura (código de 3 dígitos).
- **Punto de emisión:** cada "caja" o terminal dentro de un establecimiento (código de 3 dígitos).
- **RUC:** Registro Único de Contribuyentes. El identificador tributario del emisor (13 dígitos).
- **RIDE:** ver arriba.
- **BCE:** Banco Central del Ecuador, una de las entidades que emite certificados `.p12`.
