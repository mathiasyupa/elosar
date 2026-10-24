import type { ReactNode } from 'react';

interface FormSectionProps {
  title: string;
  description?: string;
  children: ReactNode;
  className?: string;
}

export function FormSection({ title, description, children, className = '' }: FormSectionProps) {
  return (
    <section className={`form-section ${className}`.trim()}>
      <div className="form-section-head">
        <h3 className="form-section-title">{title}</h3>
        {description && <p className="form-section-description">{description}</p>}
      </div>
      <div className="form-section-body">{children}</div>
    </section>
  );
}
