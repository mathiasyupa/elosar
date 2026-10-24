import { useState } from 'react';
import { Card } from '@/components/ui/Card';

/**
 * Development-only harness for the error boundaries. Registered in App.tsx
 * behind `import.meta.env.DEV`, so it is tree-shaken out of production builds.
 *
 * Throwing from render (rather than from a click handler) is what actually
 * exercises a boundary — React only catches errors raised during rendering,
 * lifecycle or constructors.
 */
export function CrashTest() {
  const [boom, setBoom] = useState(false);

  if (boom) {
    // Mirrors the real-world crash this protects against: reading a field off
    // an object the API didn't return.
    const patient = undefined as unknown as { allergies: string[] };
    return <p>{patient.allergies.join(', ')}</p>;
  }

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Prueba de errores</h1>
          <p>Pantalla de desarrollo para comprobar que un fallo no tumba la aplicación.</p>
        </div>
      </div>

      <Card>
        <p style={{ fontSize: 13.5, color: 'var(--text-2)', marginBottom: 14 }}>
          Al pulsar el botón esta pantalla lanza un error durante el render. Deberías ver el aviso de
          error <b>solo en esta área</b>: el menú lateral, la barra superior y el cambio de espacio de
          trabajo tienen que seguir funcionando.
        </p>
        <button type="button" className="btn primary" onClick={() => setBoom(true)}>
          Provocar un error
        </button>
      </Card>
    </>
  );
}
