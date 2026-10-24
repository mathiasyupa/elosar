import { z } from 'zod';

// Same shape as the backend's POST /auth/register payload — no transform needed.
const shared = {
  name: z.string().min(1, 'Ingresa tu nombre completo'),
  id_number: z.string().length(10, 'La cédula tiene 10 dígitos'),
  email: z.string().email('Correo no válido'),
  phone: z.string().min(7, 'Ingresa un teléfono válido'),
  city: z.string().min(1, 'Ingresa tu ciudad'),
  // First entry is the primary specialty (printed on prescriptions).
  specialty_ids: z.array(z.number().int().positive()).min(1, 'Elige al menos una especialidad'),
  // Mirrors the server rule; the account is not created without it.
  accept_terms: z.boolean().refine((value) => value === true, {
    message: 'Debes aceptar los términos para crear la cuenta',
  }),
};

export const registerSchema = z.object({
  ...shared,
  password: z.string().min(8, 'Mínimo 8 caracteres'),
});

/**
 * Signing up through Google. There is no password to ask for — the account will
 * not have one — and the address is fixed by the handoff, so making it editable
 * would only let someone fail the server's match check.
 */
export const registerWithGoogleSchema = z.object({
  ...shared,
  password: z.string().optional(),
});

// Password optional in the shared type: the resolver is what makes it required,
// and only on the password path.
export type RegisterFormValues = z.infer<typeof registerWithGoogleSchema>;

export interface WizardStep {
  id: string;
  title: string;
  fields: (keyof RegisterFormValues)[];
}

// Order drives both the progress indicator and which fields get validated
// before the wizard advances to the next step.
export const STEPS: WizardStep[] = [
  { id: 'identity', title: 'Identificación', fields: ['name', 'id_number'] },
  { id: 'contact', title: 'Contacto y acceso', fields: ['email', 'phone', 'city', 'password'] },
  { id: 'specialty', title: 'Especialidad', fields: ['specialty_ids', 'accept_terms'] },
];

/**
 * The Google variant validates neither the address nor a password on the contact
 * step: both are settled before the wizard opens. Leaving them in would block
 * the step on fields the person cannot edit.
 */
export const STEPS_WITH_GOOGLE: WizardStep[] = [
  { id: 'identity', title: 'Identificación', fields: ['name', 'id_number'] },
  { id: 'contact', title: 'Contacto', fields: ['phone', 'city'] },
  { id: 'specialty', title: 'Especialidad', fields: ['specialty_ids', 'accept_terms'] },
];
