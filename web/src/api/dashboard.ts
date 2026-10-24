import { api } from './client';
import type { ClinicDashboard, DashboardStats } from './types';

/**
 * /dashboard/stats is role-aware: a personal workspace returns DashboardStats,
 * a clinic returns the ClinicDashboard shape (queue + billing snapshot). The
 * dashboard normalizes both — see DashboardHome.
 */
export async function fetchDashboardStats(): Promise<DashboardStats | ClinicDashboard> {
  const { data } = await api.get<{ data: DashboardStats | ClinicDashboard }>('/dashboard/stats');
  return data.data;
}
