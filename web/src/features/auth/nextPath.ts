/**
 * Where to go after signing in: the page the doctor was on when the session
 * ended (`?next=`), so they land on the same patient instead of the dashboard.
 * Only paths inside Elosar — never another site (open redirect).
 */
export function safeNextPath(next: string | null): string | null {
  if (!next || !next.startsWith('/') || next.startsWith('//') || next.startsWith('/\\')) return null;
  if (next.startsWith('/login')) return null;
  return next;
}
