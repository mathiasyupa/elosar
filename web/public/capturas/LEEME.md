# Capturas de la landing

Imágenes del producto que se muestran en la galería del final de la página de
inicio (`src/features/landing/ProductCaptureGallery.tsx`).

| Archivo | Qué muestra |
|---|---|
| `agenda.png` | Semana completa de la agenda, con citas de 60 min coloreadas **por tipo** — cuatro colores; por estado sólo salen dos y la semana se ve plana |
| `consulta.png` | Paso «Evaluación clínica» de la atención: anamnesis, examen físico y revisión por sistemas |
| `diagnostico.png` | Paso «Diagnóstico»: catálogo CIE-10 e impresión clínica. **Alternativa** a `consulta.png` |
| `receta.png` | Paso «Tratamiento y prescripciones»: plan, recomendaciones, signos de alarma y medicamentos |
| `documento.png` | El PDF de la receta tal y como sale impreso, con las dos mitades (paciente y farmacia) |

**Formato:** PNG 1600×1000 (16:10, la proporción del marco de la galería), con
paleta indexada — la UI es de colores planos y eso las deja en ~40–110 KB sin
banding visible.

## Los datos son sintéticos, y eso no es opcional

Son imágenes **públicas**: llevan nombre, cédula, edad y diagnóstico a la vista.
Con datos reales serían una infracción de la LOPDP en la portada del producto.

Todo lo que aparece —pacientes, cédulas, direcciones, el caso clínico— está
inventado y se genera contra el backend **local**. El generador aborta la
ejecución si detecta una sola petición a un host que no sea el de desarrollo.

Las cédulas van a propósito en el patrón `17100000N`: el producto exige provincia
01-24 y tercer dígito < 6 (`app/Rules/EcuadorianId.php`), así que **no admite una
obviamente inválida** — la corrida de ceros es lo más lejos que se puede llegar
de una secuencia plausible sin dejar de pasar la validación. Importa porque la
cédula se lee en el PDF, en la cabecera del paciente y en **cada bloque de la
agenda**.

## Cómo se regeneran

Cuando la UI cambie lo bastante como para que estas imágenes mientan:

```bash
node design/capturas/generar.mjs
```

Las instrucciones completas (dependencias, requisitos y trampas) están en la
cabecera de ese archivo. Siembra sus propios datos, es idempotente y escribe
directamente aquí.

## `diagnostico.png` no está cableada

La galería tiene cuatro huecos y aquí hay cinco imágenes. El hueco de «Consulta»
usa `consulta.png` (la evaluación clínica) porque a tamaño de galería se lee: son
campos etiquetados con texto. `diagnostico.png` es sobre todo la parrilla de
categorías CIE-10, que a ese tamaño es una mancha de píldoras.

Se queda como alternativa. Para cambiarla, sustituye `src` y `alt` del segundo
elemento de `CAPTURE_SLOTS` en `src/features/landing/landingData.ts`; el test de
la landing comprueba que cada hueco tiene imagen y descripción, así que avisará
si se rompe algo. Al no estar referenciada, ningún visitante la descarga.
