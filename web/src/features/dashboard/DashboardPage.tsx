import { DashboardHome } from './DashboardHome';

/**
 * Home dashboard. Personal and clinic workspaces share the same solo-style KPI
 * board (DashboardHome); a clinic adds a "team on duty" card and gates each
 * section by role. Both consume the role-aware /dashboard/stats endpoint.
 */
export function DashboardPage() {
  return <DashboardHome />;
}
