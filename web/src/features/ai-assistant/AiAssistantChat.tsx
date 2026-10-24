import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { sendAssistantMessage, type AssistantNavigation, type AssistantTurn } from '@/api/ai-assistant';
import { useUiStore } from '@/store/ui';
import { Icon } from '@/components/Icon';

interface ChatMessage extends AssistantTurn {
  /** Suggested destination attached to this assistant message, if any. */
  navigate?: AssistantNavigation | null;
}

/**
 * Floating AI assistant: a button that opens a chat panel grounded in the
 * doctor's own workspace data (patients, diagnoses, attachments) via
 * backend tool-calling, and that also answers interface/help questions with
 * an actionable "Ir a X" link when the backend suggests one (open_page tool).
 * Kept mounted at all times so the conversation survives opening/closing the
 * panel within the session (lost on reload — v1 is stateless server-side,
 * see AiAssistantService).
 */
export function AiAssistantChat() {
  const open = useUiStore((s) => s.aiChatOpen);
  const toggle = useUiStore((s) => s.toggleAiChat);
  const close = useUiStore((s) => s.closeAiChat);
  const routerNavigate = useNavigate();

  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [input, setInput] = useState('');
  const [loading, setLoading] = useState(false);
  const listRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!listRef.current) return;
    listRef.current.scrollTo({ top: listRef.current.scrollHeight, behavior: 'smooth' });
  }, [messages, loading, open]);

  const send = async (event: React.FormEvent) => {
    event.preventDefault();
    const text = input.trim();
    if (!text || loading) return;

    // History sent to the API is just role/text — the navigate hint is UI-only.
    const history: AssistantTurn[] = messages.map(({ role, text }) => ({ role, text }));
    setMessages((m) => [...m, { role: 'user', text }]);
    setInput('');
    setLoading(true);

    try {
      const { reply, navigate } = await sendAssistantMessage(text, history);
      setMessages((m) => [...m, { role: 'assistant', text: reply, navigate }]);
    } catch {
      setMessages((m) => [
        ...m,
        { role: 'assistant', text: 'No pude conectar con el asistente. Intenta de nuevo en un momento.' },
      ]);
    } finally {
      setLoading(false);
    }
  };

  const goTo = (destination: AssistantNavigation) => {
    routerNavigate(destination.route);
    close();
  };

  return (
    <>
      <button className="ai-fab" type="button" onClick={toggle} aria-label="Asistente de IA">
        <Icon name={open ? 'x' : 'chat'} size={22} />
      </button>

      {open && (
        <div className="ai-chat-panel" role="dialog" aria-modal="false" aria-label="Asistente de IA">
          <div className="ai-chat-head">
            <div>
              <b>Asistente clínico</b>
              <div className="muted" style={{ fontSize: 11.5 }}>
                Datos de tu consultorio y ayuda para usar la app
              </div>
            </div>
            <button className="icon-btn" type="button" onClick={close} aria-label="Cerrar">
              <Icon name="x" size={16} />
            </button>
          </div>

          <div className="ai-chat-messages" ref={listRef}>
            {messages.length === 0 && (
              <p className="muted" style={{ fontSize: 13 }}>
                Pregúntame, por ejemplo: “¿qué pacientes tienen dolor de cabeza?” o “¿dónde cambio el
                tema de la app?”.
              </p>
            )}
            {messages.map((m, i) => (
              <div key={i} className={`ai-msg ${m.role}`}>
                {m.text}
                {m.navigate && (
                  <button type="button" className="ai-msg-link" onClick={() => goTo(m.navigate!)}>
                    Ir a {m.navigate.label} <Icon name="arrow" size={13} />
                  </button>
                )}
              </div>
            ))}
            {loading && <div className="ai-msg assistant ai-msg-loading">Pensando…</div>}
          </div>

          <form className="ai-chat-input" onSubmit={send}>
            <input
              value={input}
              onChange={(e) => setInput(e.target.value)}
              placeholder="Escribe tu pregunta…"
              disabled={loading}
              aria-label="Escribe tu pregunta al asistente"
            />
            <button className="btn primary sm" type="submit" disabled={loading || !input.trim()}>
              <Icon name="arrow" size={16} />
            </button>
          </form>
        </div>
      )}
    </>
  );
}
