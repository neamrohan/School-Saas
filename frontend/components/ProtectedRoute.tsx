"use client";

import { useEffect } from "react";
import { usePathname, useRouter } from "next/navigation";
import { useAuth } from "@/providers/AuthProvider";
import type { Role } from "@/types";
import { AppShell } from "@/components/AppShell";

const destinations: Record<Role, string> = { super_admin: "/super-admin", school_admin: "/school", teacher: "/teacher", student: "/student", parent: "/parent" };
export function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const { user, loading } = useAuth(); const router = useRouter(); const pathname = usePathname();
  useEffect(() => { if (!loading && !user) router.replace(`/login?next=${encodeURIComponent(pathname)}`); }, [loading, user, router, pathname]);
  if (loading || !user) return <div className="loading-screen"><div className="loading-spinner" /><span>Restoring your workspace...</span></div>;
  const expected = destinations[user.role]; const isAllowed = pathname === expected || pathname.startsWith(`${expected}/`) || pathname === "/profile";
  if (!isAllowed) { router.replace(expected); return <div className="loading-screen"><div className="loading-spinner" /><span>Opening your dashboard...</span></div>; }
  return <AppShell>{children}</AppShell>;
}