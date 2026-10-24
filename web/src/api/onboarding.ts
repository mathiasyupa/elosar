import { api } from './client';

export interface OnboardingStep {
  key: string;
  title: string;
  description: string;
  /** In-app route that starts the step. */
  route: string;
  done: boolean;
}

export interface Onboarding {
  dismissed: boolean;
  completed: number;
  total: number;
  steps: OnboardingStep[];
}

/**
 * "Primeros pasos" progress. Every step is derived server-side from real data
 * and filtered by the caller's permissions.
 *
 * Returns null instead of throwing when the endpoint is unavailable, so a
 * frontend deployed ahead of its backend simply hides the checklist rather than
 * showing an error for a feature the user never asked for.
 */
export async function fetchOnboarding(): Promise<Onboarding | null> {
  try {
    const { data } = await api.get<Onboarding>('/onboarding');
    return data;
  } catch {
    return null;
  }
}

export async function dismissOnboarding(): Promise<void> {
  await api.post('/onboarding/dismiss');
}
