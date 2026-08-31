import { createContext, useContext, useEffect, useState, type ReactNode } from "react";

const STORAGE_KEY = "edbo-ui-mode";

type AppModeContextValue = {
  demoMode: boolean;
  setDemoMode: (demo: boolean) => void;
};

const AppModeContext = createContext<AppModeContextValue | null>(null);

export function AppModeProvider({ children }: { children: ReactNode }) {
  const [demoMode, setDemoModeState] = useState(false);

  useEffect(() => {
    try {
      if (sessionStorage.getItem(STORAGE_KEY) === "demo") {
        setDemoModeState(true);
      }
    } catch {
      /* ignore */
    }
  }, []);

  const setDemoMode = (demo: boolean) => {
    setDemoModeState(demo);
    try {
      sessionStorage.setItem(STORAGE_KEY, demo ? "demo" : "formal");
    } catch {
      /* ignore */
    }
  };

  return <AppModeContext.Provider value={{ demoMode, setDemoMode }}>{children}</AppModeContext.Provider>;
}

export function useAppMode() {
  const ctx = useContext(AppModeContext);
  if (!ctx) {
    throw new Error("useAppMode must be used within AppModeProvider");
  }
  return ctx;
}
