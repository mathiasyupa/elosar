import type { SwitchableDoctor } from '@/api/types';

export interface RecentDoctor { name: string; email: string; photo: string | null; }
const key = 'elosar-recent-doctors';

export function recentDoctors(): RecentDoctor[] {
  try { return JSON.parse(localStorage.getItem(key) ?? '[]') as RecentDoctor[]; } catch { return []; }
}

export async function rememberDoctor(doctor: SwitchableDoctor): Promise<void> {
  // The photo is copied once (the signed URL expires); later sign-ins reuse it.
  let photo: string | null = recentDoctors().find((item) => item.email === doctor.email)?.photo ?? null;
  if (!photo && doctor.photo_url) {
    try {
      const response = await fetch(doctor.photo_url);
      const blob = await response.blob();
      photo = await new Promise((resolve) => { const reader = new FileReader(); reader.onload = () => resolve(String(reader.result)); reader.readAsDataURL(blob); });
    } catch { /* Initials remain available when the private image cannot be cached. */ }
  }
  const next = [{ name: doctor.name, email: doctor.email, photo }, ...recentDoctors().filter((item) => item.email !== doctor.email)].slice(0, 8);
  localStorage.setItem(key, JSON.stringify(next));
}

export function forgetRecentDoctors(): void { localStorage.removeItem(key); }
