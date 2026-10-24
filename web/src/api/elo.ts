import { api } from './client';

/**
 * The clinical picture Elo receives. Deliberately nothing that identifies the
 * patient: no id, name or cédula ever reaches the AI provider (ADR-017).
 */
export interface EloContext {
  age: number | null;
  sex: 'male' | 'female' | 'other' | null;
  weight_kg: number | null;
  reason: string | null;
  illness: string | null;
  diagnoses: { code: string | null; description: string | null }[];
  allergies: string[];
  /** Examen físico as written; the note and exams agents read it. */
  exam?: string | null;
  /** One line of vital signs, e.g. "temperature 38.5, heart_rate 96". */
  vitals_summary?: string | null;
}

export interface EloRxItem {
  medication_name: string;
  generic_name: string;
  concentration: string;
  dosage: string;
  route: string;
  frequency: string;
  duration_days: number | null;
  quantity: number | null;
  instructions: string;
  is_controlled: boolean;
}

export interface EloRxSuggestion {
  items: EloRxItem[];
  reason: string;
  /** Medications the model proposed that a recorded allergy ruled out. */
  removed_for_allergy: string[];
  /** The doctor's own rules (Ajustes → Agentes) that shaped this proposal. */
  rules_applied?: string[];
  disclaimer: string;
}

export type EloOutcome = 'shown' | 'accepted' | 'edited' | 'rejected';

export interface EloNoteSuggestion {
  /** The enfermedad actual, tidied — only facts the doctor wrote. */
  illness: string;
  /** Checked against the CIE-10 catalog; the description is the catalog's. */
  diagnoses: { code: string; description: string; type: 'primary' | 'secondary' | 'presumptive' }[];
  /** Codes the model proposed that do not exist in the catalog. */
  dropped_codes: number;
  reason: string;
  rules_applied?: string[];
  disclaimer: string;
}

export interface EloExamItem {
  name: string;
  study_id: number | null;
  category: string | null;
  /** False when the study is not in this clinic's catalog. */
  in_catalog: boolean;
}

export interface EloExamsSuggestion {
  type: 'laboratory' | 'imaging';
  items: EloExamItem[];
  clinical_info: string;
  reason: string;
  rules_applied?: string[];
  disclaimer: string;
}

export async function suggestNote(context: EloContext): Promise<EloNoteSuggestion> {
  const { data } = await api.post<{ data: EloNoteSuggestion }>('/elo/note', context, { timeout: 40_000 });
  return data.data;
}

/** "Escuchar con Elo": the dictated visit, sorted into the atención's fields. */
export interface EloDictationSuggestion {
  reason: string;
  illness: string;
  /** Empty unless the visit is a follow-up. */
  evolution: string;
  /** Only findings the doctor said out loud. */
  exam: string;
  diagnoses: EloNoteSuggestion['diagnoses'];
  dropped_codes: number;
  /** Why those diagnoses. */
  why: string;
  rules_applied?: string[];
  disclaimer: string;
}

/** Only text travels — the transcript, already scrubbed of cédula-like numbers. */
export async function suggestFromDictation(context: EloContext, transcript: string): Promise<EloDictationSuggestion> {
  const { data } = await api.post<{ data: EloDictationSuggestion }>(
    '/elo/dictation',
    { ...context, transcript },
    { timeout: 45_000 },
  );
  return data.data;
}

export async function suggestExams(context: EloContext): Promise<EloExamsSuggestion> {
  const { data } = await api.post<{ data: EloExamsSuggestion }>('/elo/exams', context, { timeout: 40_000 });
  return data.data;
}

export async function suggestPrescription(context: EloContext): Promise<EloRxSuggestion> {
  const { data } = await api.post<{ data: EloRxSuggestion }>('/elo/prescription', context, { timeout: 40_000 });
  return data.data;
}

/** What the doctor did with a suggestion. Fire and forget: never blocks the atención. */
export function logEloEvent(outcome: EloOutcome, itemsCount: number, agent: EloAgentKey = 'prescription'): void {
  void api
    .post('/elo/events', { agent, outcome, items_count: itemsCount })
    .catch(() => undefined);
}

// ── Ajustes → Agentes (ADR-017, part 2) ─────────────────────────────

export type EloAgentKey = 'prescription' | 'note' | 'exams' | 'dictation';

export interface EloAgent {
  key: EloAgentKey;
  name: string;
  description: string;
  /** False until that agent ships (parts 3+). */
  available: boolean;
  enabled: boolean;
  rules_count: number;
  /** Accepted or edited, out of decided, in the last 30 days. Null with no decisions yet. */
  accepted_rate: number | null;
}

export interface EloRule {
  id: number;
  agent: EloAgentKey;
  text: string;
  enabled: boolean;
}

export interface EloChatTurn {
  role: 'doctor' | 'elo';
  text: string;
}

export interface EloTeachAnswer {
  reply: string;
  /** Only proposals: nothing is saved until the doctor keeps one. */
  proposed_rules: string[];
}

export async function fetchEloAgents(): Promise<EloAgent[]> {
  const { data } = await api.get<{ data: EloAgent[] }>('/elo/agents');
  return data.data;
}

export async function setEloAgentEnabled(agent: EloAgentKey, enabled: boolean): Promise<void> {
  await api.put(`/elo/agents/${agent}`, { enabled });
}

export async function fetchEloRules(agent: EloAgentKey): Promise<EloRule[]> {
  const { data } = await api.get<{ data: EloRule[] }>(`/elo/agents/${agent}/rules`);
  return data.data;
}

export async function addEloRules(agent: EloAgentKey, texts: string[]): Promise<EloRule[]> {
  const { data } = await api.post<{ data: EloRule[] }>(`/elo/agents/${agent}/rules`, { texts });
  return data.data;
}

export async function updateEloRule(id: number, patch: Partial<Pick<EloRule, 'text' | 'enabled'>>): Promise<EloRule> {
  const { data } = await api.patch<{ data: EloRule }>(`/elo/rules/${id}`, patch);
  return data.data;
}

export async function deleteEloRule(id: number): Promise<void> {
  await api.delete(`/elo/rules/${id}`);
}

export async function teachElo(agent: EloAgentKey, message: string, history: EloChatTurn[]): Promise<EloTeachAnswer> {
  const { data } = await api.post<{ data: EloTeachAnswer }>(
    `/elo/agents/${agent}/teach`,
    { message, history: history.slice(-10) },
    { timeout: 40_000 },
  );
  return data.data;
}
