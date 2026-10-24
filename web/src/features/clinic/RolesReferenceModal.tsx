import { useQuery } from '@tanstack/react-query';
import { fetchRolesCatalog } from '@/api/clinic';
import { Modal } from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/Spinner';

interface Props {
  onClose: () => void;
}

/**
 * "¿Qué hace cada rol?" reference table. The matrix comes from the server
 * (WorkspacePermissionService::catalog), derived from the permissions actually
 * enforced — so this help can never drift from real behaviour.
 */
export function RolesReferenceModal({ onClose }: Props) {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['roles-catalog'],
    queryFn: fetchRolesCatalog,
    staleTime: 60 * 60 * 1000, // static catalog
  });

  return (
    <Modal
      title="¿Qué hace cada rol?"
      onClose={onClose}
      footer={<button className="btn primary" onClick={onClose}>Entendido</button>}
    >
      {isLoading && <Spinner label="Cargando roles…" />}
      {isError && <p style={{ color: 'var(--red)', fontSize: 13 }}>No se pudo cargar la referencia.</p>}

      {data && (
        <div className="stack" style={{ gap: 12 }}>
          <p className="muted" style={{ fontSize: 13, margin: 0 }}>
            Si asignas <b>varios roles</b> a una persona, sus permisos <b>se suman</b>. Ej.: Recepción +
            Enfermería agenda, cobra y toma signos vitales.
          </p>

          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Rol</th>
                  {data.capabilities.map((c) => (
                    <th key={c.key} className="num" style={{ whiteSpace: 'nowrap' }}>{c.label}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {data.roles.map((r) => (
                  <tr key={r.role}>
                    <td><b>{r.label}</b></td>
                    {data.capabilities.map((c) => (
                      <td key={c.key} className="num">
                        {r.grants[c.key] ? (
                          <span style={{ color: 'var(--green)', fontWeight: 700 }}>✓</span>
                        ) : (
                          <span className="muted">—</span>
                        )}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <p className="muted" style={{ fontSize: 12.5, margin: 0 }}>
            El <b>propietario</b> de la clínica suma además <b>ajustes</b> (datos fiscales, membrete) y
            <b> gestión del equipo</b>, sea cual sea su rol. La <b>historia clínica</b> queda siempre
            reservada al rol Médico.
          </p>
        </div>
      )}
    </Modal>
  );
}
