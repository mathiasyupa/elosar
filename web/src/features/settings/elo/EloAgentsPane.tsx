import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { fetchEloAgents, setEloAgentEnabled, type EloAgent, type EloAgentKey } from '@/api/elo';
import { EloBot } from '@/features/encounters/elo/EloBot';
import { Icon } from '@/components/Icon';
import { Spinner } from '@/components/ui/Spinner';
import { QueryError } from '@/components/ui/QueryError';
import { EloAgentDetail } from './EloAgentDetail';

/**
 * Ajustes → Agentes (ADR-017). One card per Elo agent with its switch; opening
 * one shows what Elo knows about this doctor and the chat to teach it more.
 * Everything here is the signed-in doctor's own — never shared with the clinic.
 */
export function EloAgentsPane() {
  const qc = useQueryClient();
  const [open, setOpen] = useState<EloAgentKey | null>(null);
  const agents = useQuery({ queryKey: ['elo', 'agents'], queryFn: fetchEloAgents });

  const toggle = useMutation({
    mutationFn: ({ key, enabled }: { key: EloAgentKey; enabled: boolean }) => setEloAgentEnabled(key, enabled),
    onMutate: ({ key, enabled }) => {
      qc.setQueryData<EloAgent[]>(['elo', 'agents'], (prev) => prev?.map((a) => (a.key === key ? { ...a, enabled } : a)));
    },
    onSettled: () => qc.invalidateQueries({ queryKey: ['elo', 'agents'] }),
  });

  if (agents.isLoading) return <Spinner label="Cargando a Elo…" />;
  if (agents.isError || !agents.data) return <QueryError error={agents.error} onRetry={() => agents.refetch()} />;

  const current = open ? agents.data.find((a) => a.key === open) : undefined;
  if (current) return <EloAgentDetail agent={current} onBack={() => setOpen(null)} />;

  return (
    <div className="elo-agents">
      <div className="elo-agents-intro">
        <EloBot mood="idle" />
        <div>
          <h3>Elo, tus agentes</h3>
          <p>
            Elo te propone cosas dentro de la atención y tú decides. Aquí lo enciendes o apagas y le enseñas cómo
            trabajas. Lo que le enseñas es solo tuyo.
          </p>
        </div>
      </div>

      <ul className="elo-agents-list">
        {agents.data.map((agent) => (
          <li key={agent.key} className={`card elo-agent ${agent.available ? '' : 'soon'}`.trim()}>
            <button
              type="button"
              className="elo-agent-open"
              disabled={!agent.available}
              onClick={() => setOpen(agent.key)}
              aria-label={`Abrir ${agent.name}`}
            >
              <span className="elo-agent-name">
                <b>{agent.name}</b>
                {!agent.available && <span className="badge plain">Pronto</span>}
              </span>
              <span className="elo-agent-desc">{agent.description}</span>
              {agent.available && (
                <span className="elo-agent-stats">
                  {agent.rules_count === 1 ? '1 regla' : `${agent.rules_count} reglas`}
                  {agent.accepted_rate !== null && ` · ${agent.accepted_rate} % aceptadas`}
                </span>
              )}
            </button>
            {agent.available && (
              <>
                <label className="switch" title={agent.enabled ? 'Encendido' : 'Apagado'}>
                  <input
                    type="checkbox"
                    aria-label={`${agent.name} encendido`}
                    checked={agent.enabled}
                    onChange={(e) => toggle.mutate({ key: agent.key, enabled: e.target.checked })}
                  />
                  <span className="track" />
                </label>
                <Icon name="chevron" size={16} className="elo-agent-chev" />
              </>
            )}
          </li>
        ))}
      </ul>
    </div>
  );
}
