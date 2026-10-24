import { useState } from 'react';
import { create } from 'zustand';
import { useQueryClient } from '@tanstack/react-query';
import { fetchProfile, updateProfile } from '@/api/profile';
import { Modal } from '@/components/ui/Modal';
import type { DoctorProfile } from '@/api/types';

export type Honorific = 'Dr.' | 'Dra.';

interface HonorificState {
  /** Resolves the document action waiting on the answer; null when closed. */
  pending: (() => void) | null;
  open: (resume: () => void) => void;
  close: () => void;
}

const useHonorificStore = create<HonorificState>((set) => ({
  pending: null,
  open: (resume) => set({ pending: resume }),
  close: () => set({ pending: null }),
}));

/**
 * Before the first receta, certificado or orden a doctor issues, ask how they
 * sign — «Dr.» or «Dra.» — and keep it on their profile, so every document
 * after that (from any screen) prints it right. Asked from whichever document
 * comes first; never again once answered. Accounts without a doctor profile
 * (front desk) go straight through.
 */
export function useHonorificGate() {
  const qc = useQueryClient();
  const open = useHonorificStore((s) => s.open);

  return async function ensureHonorific(): Promise<void> {
    let profile: DoctorProfile | undefined;
    try {
      profile = await qc.fetchQuery({ queryKey: ['profile'], queryFn: fetchProfile, staleTime: 60_000 });
    } catch {
      return; // not a doctor, or offline: never block a document over this
    }
    if (!profile || profile.honorific || profile.uses_physical_stamp_signature) return;

    await new Promise<void>((resolve) => open(resolve));
  };
}

/** Mounted once in the app shell; shows the question when a document asks. */
export function HonorificPrompt() {
  const qc = useQueryClient();
  const pending = useHonorificStore((s) => s.pending);
  const close = useHonorificStore((s) => s.close);
  const [saving, setSaving] = useState(false);

  if (!pending) return null;

  const finish = () => {
    close();
    pending();
  };

  const choose = async (honorific: Honorific) => {
    setSaving(true);
    try {
      const profile = await updateProfile({ honorific });
      qc.setQueryData(['profile'], profile);
    } catch {
      // The document still goes out (as «Dr.»); the question returns next time.
    } finally {
      setSaving(false);
      finish();
    }
  };

  return (
    <Modal
      title="¿Cómo firmas tus documentos?"
      description="Se guarda en tu perfil y se usa en recetas, certificados y órdenes. Puedes cambiarlo cuando quieras en Perfil."
      onClose={finish}
      pending={saving}
    >
      <div className="honorific-options">
        {(['Dr.', 'Dra.'] as const).map((option) => (
          <button key={option} type="button" className="honorific-option" disabled={saving} onClick={() => void choose(option)}>
            <b>{option}</b>
            <span>{option === 'Dr.' ? 'Doctor' : 'Doctora'}</span>
          </button>
        ))}
      </div>
    </Modal>
  );
}
