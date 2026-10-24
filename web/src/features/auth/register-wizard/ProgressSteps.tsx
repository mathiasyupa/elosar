import { motion } from 'framer-motion';
import type { WizardStep } from './schema';

interface Props {
  steps: WizardStep[];
  currentIndex: number;
}

/**
 * Progress indicator. On desktop it's a labelled segmented bar (one segment per
 * step); on phones it collapses to a discreet "Paso X de N" + current step title
 * over a single continuous bar, so the labels never crowd a narrow screen.
 */
export function ProgressSteps({ steps, currentIndex }: Props) {
  const pct = ((currentIndex + 1) / steps.length) * 100;

  return (
    <div
      className="auth-progress"
      role="progressbar"
      aria-label="Progreso del registro"
      aria-valuemin={1}
      aria-valuemax={steps.length}
      aria-valuenow={currentIndex + 1}
      aria-valuetext={`Paso ${currentIndex + 1} de ${steps.length}: ${steps[currentIndex].title}`}
    >
      <div className="auth-progress-mobile">
        <div className="auth-progress-heading">
          <span>
            Paso {currentIndex + 1} de {steps.length}
          </span>
          <small>{steps[currentIndex].title}</small>
        </div>
        <div className="auth-progress-track">
          <motion.div
            className="auth-progress-fill"
            initial={false}
            animate={{ width: `${pct}%` }}
            transition={{ duration: 0.45, ease: 'easeInOut' }}
          />
        </div>
      </div>

      <div className="auth-progress-desktop">
        {steps.map((step, i) => {
          const reached = i <= currentIndex;
          return (
            <div key={step.id} className={`auth-progress-step${reached ? ' is-reached' : ''}`}>
              <div className="auth-progress-track">
                <motion.div
                  className="auth-progress-fill"
                  initial={false}
                  animate={{ width: reached ? '100%' : '0%' }}
                  transition={{ duration: 0.45, ease: 'easeInOut' }}
                />
              </div>
              <span aria-current={i === currentIndex ? 'step' : undefined}>
                {i + 1}. {step.title}
              </span>
            </div>
          );
        })}
      </div>
    </div>
  );
}
