/**
 * Who answers for Elosar, in one place.
 *
 * The LOPDP (art. 12) requires the controller to be identifiable: identity, legal
 * address, phone and e-mail. Those are not decoration — they are what lets a
 * doctor, or the Superintendencia, know who to write to. Keeping them in a single
 * constant means the two legal pages can never disagree with each other.
 *
 * Elosar is run by a natural person. A RUC is a TAX identifier and is not part of
 * what the LOPDP asks for, so its absence does not block publishing this; it gets
 * added here once it exists, and the document version below is bumped.
 */

export const LEGAL_ENTITY = {
  name: 'Mathias Andrés Yupa Panchi',
  role: 'persona natural',
  /**
   * Debe coincidir con la que se declare en el RUC. Si al sacarlo se declara otra,
   * se corrige aquí y se sube la versión: un domicilio legal que no cuadra con el
   * registro tributario es una contradicción que alguien acabará notando.
   */
  address: 'Panzaleos y Pastaza, 170801, Quito, Pichincha',
  phone: '+593 98 832 4694',
  email: 'contacto@elosar.site',
} as const;

/**
 * Version of both documents. Mirrors ELOSAR_TERMS_VERSION on the backend, which is
 * what gets stamped on an account when someone accepts. If these two drift, the
 * record of "who agreed to what" stops meaning anything — bump them together.
 */
export const LEGAL_VERSION = '1.0';

/** Shown on the page so a reader can tell how old the text they are reading is. */
export const LEGAL_UPDATED_AT = '12 de agosto de 2026';

/** Días que un espacio cancelado sigue accesible para llevarse los datos. */
export const CANCELLATION_GRACE_DAYS = 60;
