import type { SriError } from '@/api/types';

/**
 * Spanish copy for SRI outcomes. Single source for both the invoice list and
 * the detail page — the labels used to be duplicated in each.
 *
 * The SRI answers in tax jargon ("ERROR ESTABLECIMIENTO CERRADO"), so each code
 * gets a plain explanation plus what to actually do about it. `fixableBy` says
 * who can act: the workspace owner reaches Ajustes, cashiers do not.
 */

export const SRI_LABELS: Record<string, string> = {
  authorized: 'Autorizada',
  pending: 'Enviando al SRI',
  rejected: 'No autorizada',
};

export const SRI_TONES: Record<string, string> = {
  authorized: 'green',
  pending: 'amber',
  rejected: 'red',
};

export interface SriExplanation {
  title: string;
  detail: string;
  action: string;
  fixableBy: 'owner' | 'nobody';
}

const CATALOG: Record<string, SriExplanation> = {
  '39': {
    title: 'El SRI no aceptó la firma',
    detail: 'La firma electrónica del comprobante no cumple lo que el SRI espera. Suele ser el certificado: vencido, de un titular distinto al RUC, o no emitido para firmar.',
    action: 'Revisa en Ajustes que el certificado .p12 sea el vigente y que su titular coincida con el RUC configurado.',
    fixableBy: 'owner',
  },
  '43': {
    title: 'El SRI ya tenía este comprobante',
    detail: 'Esta clave de acceso ya estaba registrada. Normalmente significa que sí llegó y solo faltaba consultar el resultado.',
    action: 'Vuelve a intentarlo; si sigue igual, revisa el comprobante en el portal del SRI antes de emitir otro.',
    fixableBy: 'owner',
  },
  '56': {
    title: 'El establecimiento está cerrado',
    detail: 'El SRI no tiene abierto el establecimiento que estás usando para facturar.',
    action: 'Revisa en Ajustes qué código de establecimiento tienes abierto en el SRI y corrígelo.',
    fixableBy: 'owner',
  },
};

const INTERNAL: Record<string, SriExplanation> = {
  transport: {
    title: 'No se pudo contactar al SRI',
    detail: 'El servicio del SRI no respondió. Suele ser temporal.',
    action: 'Se está reintentando solo. No hace falta que hagas nada.',
    fixableBy: 'nobody',
  },
  certificate: {
    title: 'No se pudo usar el certificado',
    detail: 'El archivo .p12 no se pudo abrir para firmar.',
    action: 'Vuelve a cargar el certificado en Ajustes y comprueba su contraseña.',
    fixableBy: 'owner',
  },
  config: {
    title: 'Faltan datos fiscales',
    detail: 'La configuración de facturación electrónica está incompleta.',
    action: 'Completa RUC, razón social y dirección de matriz en Ajustes.',
    fixableBy: 'owner',
  },
  schema: {
    title: 'El comprobante no cumple el formato del SRI',
    detail: 'El documento generado no pasó la validación estructural.',
    action: 'Esto es un problema del sistema, no tuyo. Avisa a soporte con el número de factura.',
    fixableBy: 'nobody',
  },
};

/** Falls back to the SRI's own words so the emitter is never left with nothing. */
export function explainSriError(error: SriError | null): SriExplanation | null {
  if (!error) return null;

  if (error.source === 'internal') {
    return INTERNAL[error.category] ?? INTERNAL.transport;
  }

  const known = CATALOG[error.code];
  if (known) return known;

  return {
    title: `El SRI rechazó el comprobante (código ${error.code})`,
    detail: [error.message, error.info].filter(Boolean).join('. ') || 'El SRI no dio más detalle.',
    action: 'Revisa los datos fiscales en Ajustes. Si no queda claro, avisa a soporte con este código.',
    fixableBy: 'owner',
  };
}
