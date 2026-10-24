// Maps backend status enums to a design badge tone. Unknown values fall back to
// a neutral badge so new statuses never break the UI.
const TONES: Record<string, string> = {
  // appointments
  scheduled: 'blue',
  completed: 'green',
  cancelled: 'red',
  // invoices
  draft: 'plain',
  issued: 'blue',
  partial: 'amber',
  paid: 'green',
  cancelled_invoice: 'red',
  void: 'violet',
  // SRI e-invoicing
  authorized: 'blue',
  pending: 'amber',
  processing: 'amber',
  rejected: 'red',
  error: 'red',
  not_sent: 'plain',
};

export function StatusBadge({ status, label }: { status: string | null; label: string }) {
  const tone = (status && TONES[status]) || '';
  return <span className={`badge ${tone}`.trim()}>{label}</span>;
}
