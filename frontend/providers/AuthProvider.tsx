"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from "react";
import { api, ApiRequestError } from "@/lib/api";
import type { Role, User } from "@/types";

interface AuthContextValue { user: User | null; loading: boolean; isAuthenticated: boolean; login: (email: string, password: string, selectedRole: Role) => Promise<User>; logout: () => Promise<void>; refreshUser: () => Promise<User | null>; }
interface LoginResponse { token: string; user: User; }
const AuthContext = createContext<AuthContextValue | undefined>(undefined);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);

  const clearSession = useCallback(() => { window.localStorage.removeItem("school-saas-token"); window.localStorage.removeItem("school-saas-user"); setUser(null); }, []);
  const refreshUser = useCallback(async () => {
    try { const result = await api.get<{ user: User }>("/me"); setUser(result.user); window.localStorage.setItem("school-saas-user", JSON.stringify(result.user)); return result.user; }
    catch { clearSession(); return null; }
  }, [clearSession]);
  const login = useCallback(async (email: string, password: string, selectedRole: Role) => {
    const result = await api.post<LoginResponse>("/login", { email, password, role: selectedRole });
    if (!result || typeof result.token !== "string" || !result.token || !result.user || typeof result.user !== "object" || !result.user.role) {
      throw new ApiRequestError("The server returned an invalid login response.", 502);
    }
    if (result.user.role !== selectedRole) {
      throw new ApiRequestError("This account does not belong to the selected login type.", 403);
    }
    window.localStorage.setItem("school-saas-token", result.token); window.localStorage.setItem("school-saas-user", JSON.stringify(result.user)); setUser(result.user); return result.user;
  }, []);
  const logout = useCallback(async () => { try { if (window.localStorage.getItem("school-saas-token")) await api.post("/logout", {}); } finally { clearSession(); } }, [clearSession]);

  useEffect(() => {
    const initialize = async () => {
      const cached = window.localStorage.getItem("school-saas-user");
      if (cached) try { setUser(JSON.parse(cached) as User); } catch { window.localStorage.removeItem("school-saas-user"); }
      if (window.localStorage.getItem("school-saas-token")) await refreshUser();
      setLoading(false);
    };
    void initialize();
    const unauthorized = () => clearSession(); window.addEventListener("school-saas:unauthorized", unauthorized); return () => window.removeEventListener("school-saas:unauthorized", unauthorized);
  }, [clearSession, refreshUser]);

  const value = useMemo(() => ({ user, loading, isAuthenticated: Boolean(user), login, logout, refreshUser }), [user, loading, login, logout, refreshUser]);
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() { const context = useContext(AuthContext); if (!context) throw new Error("useAuth must be used inside AuthProvider"); return context; }