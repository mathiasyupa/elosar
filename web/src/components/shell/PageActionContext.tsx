import {
  createContext,
  useContext,
  useEffect,
  useMemo,
  useState,
  type Dispatch,
  type ReactNode,
  type SetStateAction,
} from 'react';

export interface PageAction {
  id: string;
  label: string;
  icon: string;
  onInvoke: () => void;
}

interface PageActionContextValue {
  actions: PageAction[];
  setActions: Dispatch<SetStateAction<PageAction[]>>;
}

const PageActionContext = createContext<PageActionContextValue | null>(null);

export function PageActionProvider({ children }: { children: ReactNode }) {
  const [actions, setActions] = useState<PageAction[]>([]);
  const value = useMemo(() => ({ actions, setActions }), [actions]);
  return <PageActionContext.Provider value={value}>{children}</PageActionContext.Provider>;
}

function usePageActionContext(): PageActionContextValue {
  const value = useContext(PageActionContext);
  if (!value) throw new Error('Page actions require PageActionProvider.');
  return value;
}

export function usePageAction(): PageAction[] {
  return usePageActionContext().actions;
}

export function usePageActionRegistration(action: PageAction | null): void {
  const { setActions } = usePageActionContext();
  useEffect(() => {
    if (!action) return;
    setActions((current) => {
      if (current.some((a) => a.id === action.id)) return current;
      return [...current, action];
    });
    return () => {
      setActions((current) => current.filter((a) => a.id !== action.id));
    };
  }, [action, setActions]);
}
