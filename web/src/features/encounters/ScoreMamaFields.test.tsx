// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ScoreMamaFields, type ScoreMamaCapture } from './ScoreMamaFields';

const EMPTY: ScoreMamaCapture = {
  oxygen_therapy: null,
  consciousness_level: null,
  urine_output_ml_h: '',
  urine_output_not_quantifiable: false,
};

function renderFields({
  value = EMPTY,
  measured = {},
  alwaysOn = false,
}: {
  value?: ScoreMamaCapture;
  measured?: Record<string, string>;
  alwaysOn?: boolean;
} = {}) {
  const onChange = vi.fn();
  render(
    <ScoreMamaFields value={value} onChange={onChange} measured={measured} alwaysOn={alwaysOn} />,
  );
  return { onChange };
}

afterEach(cleanup);

describe('ScoreMamaFields', () => {
  // Pedirle gasto urinario al señor que viene por dolor de rodilla es la forma
  // más rápida de que un campo clínico se empiece a rellenar en falso.
  it('stays out of the way until someone asks for it', () => {
    renderFields();

    expect(screen.getByRole('checkbox', { name: /Aplicar Score MAMÁ/i })).toBeTruthy();
    expect(screen.queryByLabelText(/Oxigenoterapia/i)).toBeNull();
  });

  it('opens when the clinician turns it on', async () => {
    const user = userEvent.setup();
    renderFields();

    await user.click(screen.getByRole('checkbox', { name: /Aplicar Score MAMÁ/i }));

    expect(screen.getByLabelText(/Oxigenoterapia/i)).toBeTruthy();
    expect(screen.getByLabelText(/Nivel de consciencia/i)).toBeTruthy();
    expect(screen.getByLabelText(/Gasto urinario/i)).toBeTruthy();
  });

  // En un control prenatal no debería depender de que alguien se acuerde.
  it('is already open, and cannot be closed, on a prenatal consultation', () => {
    renderFields({ alwaysOn: true });

    expect(screen.getByLabelText(/Oxigenoterapia/i)).toBeTruthy();
    expect(screen.queryByRole('checkbox', { name: /Aplicar Score MAMÁ/i })).toBeNull();
  });

  /** El ejemplo resuelto del protocolo, esta vez en pantalla. */
  it('shows the score the protocol prints for the worked example', () => {
    renderFields({
      alwaysOn: true,
      value: {
        oxygen_therapy: 'ambient_air',
        consciousness_level: 'alert',
        urine_output_ml_h: '',
        urine_output_not_quantifiable: true,
      },
      measured: {
        temp: '36.5',
        bp_sys: '140',
        bp_dia: '80',
        hr: '105',
        rr: '22',
        spo2: '96',
      },
    });

    expect(screen.getByText('3')).toBeTruthy();
    expect(screen.getByText(/Tratar y referir/i)).toBeTruthy();
  });

  // Un solo parámetro en 3 es emergencia aunque el total sea bajo. Si la pantalla
  // no lo dice, el cálculo no sirve de nada.
  it('says emergency when one parameter alone is at 3', () => {
    renderFields({
      alwaysOn: true,
      value: { ...EMPTY, oxygen_therapy: 'ambient_air', consciousness_level: 'alert', urine_output_not_quantifiable: true },
      measured: { temp: '36.5', bp_sys: '65', bp_dia: '70', hr: '80', rr: '16', spo2: '97' },
    });

    expect(screen.getByText(/activar clave obstétrica/i)).toBeTruthy();
  });

  // Un puntaje parcial que se muestre como si fuera completo miente.
  it('warns that a partial reading is not the whole score', () => {
    renderFields({ alwaysOn: true, measured: { temp: '36.5' } });

    expect(screen.getByText(/faltan/i)).toBeTruthy();
  });

  it('lets the nurse say the output could not be measured, and stops asking for a number', async () => {
    const user = userEvent.setup();
    const { onChange } = renderFields({ alwaysOn: true });

    await user.click(screen.getByRole('checkbox', { name: /No cuantificable/i }));

    expect(onChange).toHaveBeenCalledWith(
      expect.objectContaining({ urine_output_not_quantifiable: true }),
    );
  });

  it('disables the millilitres field while the output is not quantifiable', () => {
    renderFields({
      alwaysOn: true,
      value: { ...EMPTY, urine_output_not_quantifiable: true },
    });

    expect((screen.getByLabelText(/Gasto urinario/i) as HTMLInputElement).disabled).toBe(true);
  });
});
