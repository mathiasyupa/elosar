import { Icon } from '@/components/Icon';
import { formatDate } from '@/i18n/es';
import type { Pregnancy } from '@/api/types';

/** Edad gestacional y FPP del embarazo en curso, para las zonas de aviso que ya
 *  existen: `.pr-alerts` en el expediente y `.eh-alerts` en la atención.
 *
 *  Por qué aquí y no en una tarjeta propia: en un embarazo en curso, "cuántas
 *  semanas tiene" condiciona cada decisión de la consulta, igual que una alergia
 *  condiciona cada receta. Un dato que hay que ir a buscar es un dato que a veces
 *  no se busca.
 *
 *  La edad la calcula el servidor y llega en `gestational_age`; no se recalcula
 *  aquí para que la pantalla no pueda contradecir a la historia clínica. */
export function PregnancyChip({ pregnancy }: { pregnancy: Pregnancy }) {
  if (pregnancy.status !== 'active') return null;

  const age = pregnancy.gestational_age;

  // Un embarazo sin FUM ni ecografía no es "0 semanas": es un embarazo sin datar,
  // y decirlo es más útil que esconder el chip.
  if (!age.is_valid) {
    return (
      <span className="preg-chip" title="Falta la FUM o una ecografía para calcular la edad gestacional.">
        <Icon name="cal" size={12} />
        Embarazo sin datar
      </span>
    );
  }

  const tone = age.is_post_term ? 'post-term' : age.is_term ? 'term' : '';
  const source = pregnancy.dating.source_used === 'ultrasound' ? 'por eco' : 'por FUM';

  return (
    <span
      className={`preg-chip ${tone}`.trim()}
      title={`Embarazo en curso · datación ${source}${
        pregnancy.dating.discrepancy_days != null
          ? ` · FUM y ecografía difieren ${Math.abs(pregnancy.dating.discrepancy_days)} días`
          : ''
      }`}
    >
      <Icon name="cal" size={12} />
      {age.weeks} s {age.remainder_days} d
      {pregnancy.edd_date && (
        <span className="preg-edd">· FPP {formatDate(pregnancy.edd_date)}</span>
      )}
      <span className="preg-source">· {source}</span>
    </span>
  );
}
