import { api } from './client';

export interface AssistantTurn {
  role: 'user' | 'assistant';
  text: string;
}

export interface AssistantNavigation {
  route: string;
  label: string;
}

export interface AssistantReply {
  reply: string;
  navigate: AssistantNavigation | null;
}

/** Sends a question (+ prior turns) to the clinical AI assistant. Stateless on the server — the full history is replayed on every call. */
export async function sendAssistantMessage(message: string, history: AssistantTurn[]): Promise<AssistantReply> {
  const { data } = await api.post<{ data: AssistantReply }>('/assistant/chat', {
    message,
    history,
  });
  return data.data;
}
