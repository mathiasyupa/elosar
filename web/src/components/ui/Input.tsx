import { forwardRef, type InputHTMLAttributes, type ReactNode } from 'react';

interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  label?: string;
  error?: string;
  trailingAction?: ReactNode;
}

export const Input = forwardRef<HTMLInputElement, InputProps>(function Input(
  { label, error, trailingAction, className = '', id, 'aria-describedby': describedBy, ...props },
  ref,
) {
  const errorId = id && error ? `${id}-error` : undefined;
  const ariaDescribedBy = [describedBy, errorId].filter(Boolean).join(' ') || undefined;
  const displayLabel = label?.replace(/\s*\*$/, '');

  return (
    <div className="field-group">
      {displayLabel && (
        <label htmlFor={id} className="field-label">
          {displayLabel}
          {props.required && <span aria-hidden="true"> *</span>}
        </label>
      )}
      {trailingAction ? (
        <div className="field-control has-trailing-action">
          <input
            id={id}
            ref={ref}
            aria-invalid={error ? true : undefined}
            aria-describedby={ariaDescribedBy}
            className={`field ${error ? 'err' : ''} ${className}`.trim()}
            {...props}
          />
          <span className="field-control-action">{trailingAction}</span>
        </div>
      ) : (
        <input
          id={id}
          ref={ref}
          aria-invalid={error ? true : undefined}
          aria-describedby={ariaDescribedBy}
          className={`field ${error ? 'err' : ''} ${className}`.trim()}
          {...props}
        />
      )}
      {error && (
        <p className="field-error mono" id={errorId}>
          {error}
        </p>
      )}
    </div>
  );
});
