import { create } from 'zustand';

export type ToastKind = 'success' | 'error' | 'info';

export interface Toast {
  id: number;
  kind: ToastKind;
  message: string;
}

interface UiState {
  /** Global "Nueva consulta" patient picker (topbar CTA, dashboard, legacy route). */
  quickSearchOpen: boolean;
  openQuickSearch: () => void;
  closeQuickSearch: () => void;

  /** Global AI assistant chat panel (floating button, any screen). */
  aiChatOpen: boolean;
  openAiChat: () => void;
  closeAiChat: () => void;
  toggleAiChat: () => void;

  toasts: Toast[];
  toast: (message: string, kind?: ToastKind) => void;
  dismissToast: (id: number) => void;
}

let toastSeq = 0;

/** Lightweight, non-persisted UI state: the global quick-search modal and a
 *  toast queue. Kept out of the auth store so it never touches localStorage. */
export const useUiStore = create<UiState>((set, get) => ({
  quickSearchOpen: false,
  openQuickSearch: () => set({ quickSearchOpen: true }),
  closeQuickSearch: () => set({ quickSearchOpen: false }),

  aiChatOpen: false,
  openAiChat: () => set({ aiChatOpen: true }),
  closeAiChat: () => set({ aiChatOpen: false }),
  toggleAiChat: () => set((s) => ({ aiChatOpen: !s.aiChatOpen })),

  toasts: [],
  toast: (message, kind = 'success') => {
    const id = ++toastSeq;
    set((s) => ({ toasts: [...s.toasts, { id, kind, message }] }));
    setTimeout(() => get().dismissToast(id), 4000);
  },
  dismissToast: (id) => set((s) => ({ toasts: s.toasts.filter((toApply) => toApply.id !== id) })),
}));
