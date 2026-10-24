<?php

/**
 * LOPDP (Ley Orgánica de Protección de Datos Personales — Ecuador) settings.
 *
 * `consent_version` stamps the version of the informed-consent text a patient
 * accepted; bump it whenever the wording changes so old consents can be
 * distinguished. `clinical_retention_years` documents the legal retention period
 * for the medical record — it is intentionally NOT auto-enforced (deleting a
 * clinical history may conflict with the sanitary conservation obligation);
 * confirm the exact period with legal counsel before wiring any purge.
 */
return [
    'consent_version'          => env('LOPDP_CONSENT_VERSION', '1.0'),
    'clinical_retention_years' => (int) env('LOPDP_RETENTION_YEARS', 10),

    /*
     * Version of Elosar's own terms and privacy policy that a DOCTOR accepts on
     * signing up. Distinct from `consent_version`, which is what a PATIENT
     * consents to inside a clinic: different document, different parties.
     *
     * Bump it whenever the published wording changes. Accounts keep the version
     * they accepted, so it stays possible to tell who agreed to what.
     */
    'terms_version' => env('ELOSAR_TERMS_VERSION', '1.0'),

    /*
     * Days a cancelled workspace stays reachable so its owner can still get
     * their data out before anything is removed.
     *
     * Elosar is the PROCESSOR here; the doctor is the controller, and the
     * ten-year duty over the clinical record is theirs. Our obligation on the
     * way out is to hand it over, not to keep it — hence a window, not an
     * archive.
     */
    'cancellation_grace_days' => (int) env('ELOSAR_CANCELLATION_GRACE_DAYS', 60),
];
