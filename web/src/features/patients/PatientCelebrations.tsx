import { useMutation, useQuery } from '@tanstack/react-query';
import { fetchPatientCelebrations, sendGreetingEmail, type GreetingType } from '@/api/patients';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { Icon } from '@/components/Icon';
import { PatientWorkspaceLink } from '@/components/PatientWorkspaceLink';
import { WhatsAppButton } from '@/components/WhatsAppButton';
import { EmailButton } from '@/components/EmailButton';
import { avatarColor, initials } from '@/lib/theme';
import { formatMoney } from '@/i18n/es';
import {
  anniversaryMessage,
  birthdayMessage,
  reactivationMessage,
  welcomeMessage,
} from '@/lib/whatsapp';
import type { CelebrationPatient, PatientCelebrations as Celebrations } from '@/api/types';

/** Days from today until the next occurrence of an MM-DD date. */
function daysUntil(md: string | undefined): number | null {
  if (!md) return null;
  const [m, d] = md.split('-').map(Number);
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  let next = new Date(today.getFullYear(), m - 1, d);
  if (next < today) next = new Date(today.getFullYear() + 1, m - 1, d);
  return Math.round((next.getTime() - today.getTime()) / 86_400_000);
}

interface GroupConfig {
  key: keyof Celebrations;
  type: GreetingType;
  title: string;
  icon: string;
  hint: string;
  subtitle: (p: CelebrationPatient) => string;
  message: (p: CelebrationPatient) => string;
  cta: string;
  years?: (p: CelebrationPatient) => number | undefined;
}

const GROUPS: Record<string, GroupConfig> = {
  birthdays_today: {
    key: 'birthdays_today', type: 'birthday', title: 'Cumpleaños de hoy', icon: 'gift',
    hint: 'Hoy nadie cumple años.',
    subtitle: (p) => (p.age != null ? `Cumple ${p.age} años hoy` : 'Cumple años hoy'),
    message: (p) => birthdayMessage(p.name), cta: 'Felicitar',
  },
  anniversaries_today: {
    key: 'anniversaries_today', type: 'anniversary', title: 'Aniversario como paciente', icon: 'cal',
    hint: 'Hoy no hay aniversarios de primera visita.',
    subtitle: (p) => `${p.years} año${p.years === 1 ? '' : 's'} desde su primera consulta`,
    message: (p) => anniversaryMessage(p.name, p.years ?? 1), cta: 'Saludar',
    years: (p) => p.years,
  },
  birthdays_week: {
    key: 'birthdays_week', type: 'birthday', title: 'Cumpleaños esta semana', icon: 'cal',
    hint: 'Sin cumpleaños en los próximos 7 días.',
    subtitle: (p) => {
      const n = daysUntil(p.birthday_md);
      return n != null ? `Cumple en ${n} día${n === 1 ? '' : 's'}` : 'Próximo cumpleaños';
    },
    message: (p) => birthdayMessage(p.name), cta: 'Felicitar',
  },
  new_patients: {
    key: 'new_patients', type: 'welcome', title: 'Da la bienvenida', icon: 'users',
    hint: 'Sin pacientes nuevos en los últimos 30 días.',
    subtitle: () => 'Paciente agregado recientemente',
    message: (p) => welcomeMessage(p.name), cta: 'Dar bienvenida',
  },
  reactivate: {
    key: 'reactivate', type: 'reactivation', title: 'Reactivar pacientes', icon: 'bell',
    hint: 'No hay pacientes inactivos por reactivar.',
    // Worked top to bottom: the server ranks by what the patient has left at
    // the practice, so the subtitle shows that history, not just the gap.
    subtitle: (p) => {
      const gap = p.months_since != null
        ? `Sin visitas hace ${p.months_since} mes${p.months_since === 1 ? '' : 'es'}`
        : 'Sin visitas recientes';
      const visits = p.visits != null ? ` · ${p.visits} consulta${p.visits === 1 ? '' : 's'}` : '';
      const spent = p.spent != null && Number(p.spent) > 0 ? ` · ${formatMoney(p.spent)}` : '';
      return `${gap}${visits}${spent}`;
    },
    message: (p) => reactivationMessage(p.name), cta: 'Invitar a control',
  },
};

const SECTIONS: { label: string; groups: (keyof Celebrations)[] }[] = [
  { label: 'Hoy', groups: ['birthdays_today', 'anniversaries_today'] },
  { label: 'Próximos días', groups: ['birthdays_week'] },
  { label: 'Fidelización', groups: ['new_patients', 'reactivate'] },
];

export function PatientCelebrations() {
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const toast = useUiStore((s) => s.toast);
  const { data, isLoading, isError } = useQuery({
    queryKey: ['patient-celebrations', activeWorkspaceId],
    queryFn: fetchPatientCelebrations,
  });

  const greet = useMutation({
    mutationFn: (v: { id: number; type: GreetingType; years?: number }) => sendGreetingEmail(v.id, v.type, v.years),
    onSuccess: () => toast('Saludo enviado por correo.', 'success'),
    onError: () => toast('No se pudo enviar el correo. ¿El paciente tiene email?', 'error'),
  });

  if (isLoading) return <Card><Spinner label="Cargando hitos…" /></Card>;
  if (isError || !data) return <Card><p style={{ color: 'var(--red)', fontSize: 13 }}>No se pudieron cargar los hitos.</p></Card>;

  const totalToday = data.birthdays_today.length + data.anniversaries_today.length;

  return (
    <div className="stack" style={{ gap: 18 }}>
      <p className="muted" style={{ fontSize: 13, margin: 0 }}>
        {totalToday > 0
          ? `Comunicación de relación: tienes ${totalToday} motivo(s) para saludar a tus pacientes hoy. Un saludo por WhatsApp o correo fortalece la relación.`
          : 'Comunicación de relación mediante cumpleaños, aniversarios y reactivación para saludar por WhatsApp o correo.'}
      </p>

      {SECTIONS.map((section) => (
        <div key={section.label} className="stack" style={{ gap: 10 }}>
          <div className="nav-label" style={{ margin: 0 }}>{section.label}</div>
          <div className="grid" style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(330px, 1fr))', gap: 14 }}>
            {section.groups.map((key) => {
              const group = GROUPS[key];
              const patients = data[key];
              return (
                <Card key={key}>
                  <div className="card-head" style={{ padding: 0, marginBottom: 10 }}>
                    <h3 style={{ margin: 0, fontSize: 14.5, display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                      <Icon name={group.icon} size={15} />
                      {group.title}
                      <span className="badge accent plain">{patients.length}</span>
                    </h3>
                  </div>
                  {patients.length === 0 ? (
                    <p className="muted" style={{ fontSize: 13, margin: 0 }}>{group.hint}</p>
                  ) : (
                    <div className="stack" style={{ gap: 10 }}>
                      {patients.map((p) => (
                        <div key={`${key}-${p.id}`} className="stack" style={{ gap: 6 }}>
                          <div className="who" style={{ minWidth: 0 }}>
                            <span className="avatar-sm" style={{ background: avatarColor(p.id) }}>{initials(p.name)}</span>
                            <div style={{ minWidth: 0 }}>
                              <PatientWorkspaceLink patientId={p.id} style={{ fontWeight: 600 }}>
                                {p.name}
                              </PatientWorkspaceLink>
                              <span className="muted" style={{ display: 'block', fontSize: 12 }}>{group.subtitle(p)}</span>
                            </div>
                          </div>
                          <div className="row wrap" style={{ gap: 6 }}>
                            <WhatsAppButton phone={p.phone} message={group.message(p)} label={group.cta} />
                            <EmailButton
                              email={p.email}
                              pending={greet.isPending && greet.variables?.id === p.id}
                              onSend={() => greet.mutate({ id: p.id, type: group.type, years: group.years?.(p) })}
                              label="Por correo"
                            />
                          </div>
                        </div>
                      ))}
                    </div>
                  )}
                </Card>
              );
            })}
          </div>
        </div>
      ))}
    </div>
  );
}
