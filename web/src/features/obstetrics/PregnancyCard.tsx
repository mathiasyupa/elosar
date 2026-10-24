import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/Icon';
import { Spinner } from '@/components/ui/Spinner';
import { formatDate } from '@/i18n/es';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import { fetchScreenings, saveScreening } from '@/api/obstetrics';
import { PregnancyCurve } from './PregnancyCurve';
import { PregnancyCalendar } from './PregnancyCalendar';
import { PregnancyBloodPressure } from './PregnancyBloodPressure';
import type { Pregnancy, PrenatalScreening } from '@/api/types';

/** Una fila del carnet: el examen, cuándo lo pide la guía y qué se registró. */
function ScreeningRow({
  screening,
  onSave,
  pending,
  canEdit,
}: {
  screening: PrenatalScreening;
  onSave: (code: string, result: string, resultAt: string) => void;
  pending: boolean;
  canEdit: boolean;
}) {
  const [result, setResult] = useState(screening.result ?? '');
  const [resultAt, setResultAt] = useState(screening.result_at ?? '');
  const dirty = result !== (screening.result ?? '') || resultAt !== (screening.result_at ?? '');

  return (
    <tr>
      <td>
        {screening.label}
        {/* La guía lo pide solo ante un factor de riesgo. Sin decirlo, una casilla
            vacía se lee como un olvido — y no lo es. */}
        {!screening.universal && (
          <span className="tag" title={screening.when}>
            según riesgo
          </span>
        )}
        <p className="muted scr-when">{screening.when}</p>
      </td>
      <td>
        <input
          className="field"
          value={result}
          placeholder="Resultado"
          disabled={!canEdit}
          onChange={(e) => setResult(e.target.value)}
          aria-label={`Resultado de ${screening.label}`}
        />
      </td>
      <td>
        <input
          className="field"
          type="date"
          value={resultAt}
          disabled={!canEdit}
          onChange={(e) => setResultAt(e.target.value)}
          aria-label={`Fecha de ${screening.label}`}
        />
      </td>
      <td className="num">
        {canEdit && dirty && (
          <Button size="sm" disabled={pending} onClick={() => onSave(screening.code, result, resultAt)}>
            Guardar
          </Button>
        )}
      </td>
    </tr>
  );
}

/**
 * Un embarazo con su carnet perinatal.
 *
 * El carnet muestra **todo** el calendario del MSP, no solo lo hecho: sirve
 * justamente para ver lo que falta. Ver `knowledge/reference/CARNE_PERINATAL.md`.
 */
export function PregnancyCard({
  pregnancy,
  canEdit,
  onClose,
  onEdit,
  actions = true,
}: {
  pregnancy: Pregnancy;
  canEdit: boolean;
  onClose: () => void;
  onEdit: () => void;
  /** False when the actions live beside the card (the gyneco file's pending column). */
  actions?: boolean;
}) {
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const toast = useUiStore((s) => s.toast);
  const qc = useQueryClient();
  const [openCard, setOpenCard] = useState(pregnancy.status === 'active');

  const screenings = useQuery({
    queryKey: ['pregnancy-screenings', activeWorkspaceId, pregnancy.id],
    queryFn: () => fetchScreenings(pregnancy.id),
    enabled: openCard,
  });

  const save = useMutation({
    mutationFn: (v: { code: string; result: string; resultAt: string }) =>
      saveScreening(pregnancy.id, {
        code: v.code,
        result: v.result || null,
        result_at: v.resultAt || null,
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['pregnancy-screenings', activeWorkspaceId, pregnancy.id] });
      toast('Examen registrado.', 'success');
    },
    onError: () => toast('No se pudo registrar el examen.', 'error'),
  });

  const age = pregnancy.gestational_age;
  const dating = pregnancy.dating.source_used === 'ultrasound' ? 'por ecografía' : 'por FUM';
  const done = (screenings.data ?? []).filter((s) => s.recorded).length;

  return (
    <Card padded={false} className="preg-card">
      <div className="card-head">
        <h3>
          {pregnancy.status === 'active' ? 'Embarazo en curso' : 'Embarazo'}
          {pregnancy.status !== 'active' && (
            <span className="badge plain">{pregnancy.outcome_label ?? pregnancy.status_label}</span>
          )}
        </h3>
        <span className="sub">
          {/* The active pregnancy reads its age large in the hero below. */}
          {pregnancy.status === 'active'
            ? null
            : pregnancy.outcome_date
              ? `Terminó el ${formatDate(pregnancy.outcome_date)}`
              : 'Sin datar'}
        </span>
      </div>

      {/* The three numbers a control is planned around, read at a glance. */}
      {pregnancy.status === 'active' && (
        <div className="preg-hero">
          <div>
            <span className="preg-hero-label">Edad gestacional</span>
            <strong className="mono">{age.is_valid ? `${age.weeks}+${age.remainder_days}` : '—'}</strong>
            <span className="preg-hero-sub">semanas · {dating}</span>
          </div>
          <div>
            <span className="preg-hero-label">Fecha probable de parto</span>
            <strong className="mono">{pregnancy.edd_date ? formatDate(pregnancy.edd_date) : '—'}</strong>
          </div>
          {pregnancy.elective_cesarean_date && (
            <div>
              <span className="preg-hero-label">Cesárea electiva (39 sem.)</span>
              <strong className="mono">{formatDate(pregnancy.elective_cesarean_date)}</strong>
            </div>
          )}
        </div>
      )}

      {pregnancy.status === 'active' && (
        <div className="card-pad preg-cal-wrap">
          <PregnancyCalendar pregnancy={pregnancy} />
        </div>
      )}

      <div className="card-pad stack-sm">
        <dl className="kv">
          <dt>FUM</dt>
          <dd className="mono">{pregnancy.lmp_date ? formatDate(pregnancy.lmp_date) : '—'}</dd>
          {/* In the active pregnancy these two already read large in the hero. */}
          {pregnancy.status !== 'active' && (
            <>
              <dt>Fecha probable de parto</dt>
              <dd className="mono">{pregnancy.edd_date ? formatDate(pregnancy.edd_date) : '—'}</dd>
            </>
          )}
          {pregnancy.status !== 'active' && pregnancy.elective_cesarean_date && (
            <>
              {/* 39 semanas, una menos que la FPP — calendario, no criterio
                  clínico: no dice si procede cesárea, solo cuándo caería. */}
              <dt>Cesárea electiva (39 sem.)</dt>
              <dd className="mono">{formatDate(pregnancy.elective_cesarean_date)}</dd>
            </>
          )}
          {pregnancy.ultrasound_date && (
            <>
              <dt>Ecografía</dt>
              <dd className="mono">
                {formatDate(pregnancy.ultrasound_date)} · {pregnancy.ultrasound_weeks}+
                {pregnancy.ultrasound_days ?? 0}
              </dd>
            </>
          )}
        </dl>

        {/* Las dos dataciones difieren y el médico tiene que poder verlo: el
            producto no redata solo porque la norma no publica un umbral. */}
        {pregnancy.dating.discrepancy_days != null &&
          Math.abs(pregnancy.dating.discrepancy_days) >= 7 && (
            <div className="alert amber">
              <span className="ai">
                <Icon name="alert" size={15} />
              </span>
              <div>
                <b>La FUM y la ecografía no coinciden</b>
                <p>
                  Difieren {Math.abs(pregnancy.dating.discrepancy_days)} días. Ahora manda la
                  datación {dating}; puedes cambiarla al editar el embarazo.
                </p>
              </div>
            </div>
          )}

        {pregnancy.risk_notes && (
          <div>
            <span className="field-label">Riesgo</span>
            <p>{pregnancy.risk_notes}</p>
          </div>
        )}

        {canEdit && actions && (
          <div className="row wrap">
            <Button variant="ghost" size="sm" onClick={onEdit}>
              <Icon name="edit" size={14} /> Editar datación
            </Button>
            {pregnancy.status === 'active' && (
              <Button variant="ghost" size="sm" onClick={onClose}>
                <Icon name="check" size={14} /> Registrar desenlace
              </Button>
            )}
          </div>
        )}
      </div>

      <div className="card-head preg-card-sub">
        <h3>Curva del embarazo</h3>
        <span className="sub">altura uterina y peso por semana</span>
      </div>
      <div className="card-pad">
        <PregnancyCurve pregnancyId={pregnancy.id} />
      </div>

      <div className="card-head preg-card-sub">
        <h3>Presión arterial</h3>
        <span className="sub">por control · referencia 140/90</span>
      </div>
      <div className="card-pad">
        <PregnancyBloodPressure pregnancyId={pregnancy.id} />
      </div>

      <div className="card-head preg-card-sub">
        <h3>
          Exámenes del control prenatal
          {screenings.data && (
            <span className="badge plain">
              {done} de {screenings.data.length}
            </span>
          )}
        </h3>
        <button className="btn ghost sm" onClick={() => setOpenCard((v) => !v)}>
          {openCard ? 'Ocultar' : 'Ver carnet'}
        </button>
      </div>

      {openCard &&
        (screenings.isLoading ? (
          <div className="card-pad">
            <Spinner label="Cargando el carnet…" />
          </div>
        ) : (
          <div className="table-scroll">
            <table className="tbl scr-table">
              <thead>
                <tr>
                  <th>Examen</th>
                  <th>Resultado</th>
                  <th>Fecha</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {(screenings.data ?? []).map((s) => (
                  <ScreeningRow
                    key={s.code}
                    screening={s}
                    canEdit={canEdit}
                    pending={save.isPending}
                    onSave={(code, result, resultAt) => save.mutate({ code, result, resultAt })}
                  />
                ))}
              </tbody>
            </table>
          </div>
        ))}
    </Card>
  );
}
