import { DocumentsBrowser } from '@/features/documents/DocumentsPage';
import { usePatientWorkspace } from './context';

/**
 * The expediente's Documentos tab: the same screen as Documentos in the main
 * panel (search, type chips, list by date and the document beside it), showing
 * only this patient's documents.
 */
export function PatientDocumentsTab() {
  const { patient } = usePatientWorkspace();
  return <DocumentsBrowser patient={patient} />;
}
