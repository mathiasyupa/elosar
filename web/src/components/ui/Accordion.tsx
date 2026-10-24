import type { ReactNode } from 'react';
import { Icon } from '@/components/Icon';

export interface AccordionItemDef {
  key: string;
  title: string;
  /** Pinta un indicador junto al título cuando el grupo ya tiene datos, para
   *  que el médico no tenga que abrir cada uno para ver qué falta llenar. */
  hasContent?: boolean;
  defaultOpen?: boolean;
  children: ReactNode;
}

interface AccordionProps {
  items: AccordionItemDef[];
}

/** Lista de bloques desplegables. Usa <details>/<summary> nativos: accesible
 *  por teclado y lectores de pantalla sin manejar estado abierto/cerrado en React. */
export function Accordion({ items }: AccordionProps) {
  return (
    <div className="accordion">
      {items.map((item) => (
        <details key={item.key} className="accordion-item" open={item.defaultOpen}>
          <summary className="accordion-summary">
            <Icon name="chevron" size={16} className="accordion-chevron" />
            <span className="accordion-title">{item.title}</span>
            {item.hasContent && <span className="accordion-dot" aria-label="Con datos registrados" />}
          </summary>
          <div className="accordion-body">{item.children}</div>
        </details>
      ))}
    </div>
  );
}
