"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { useAuth } from "@/providers/AuthProvider";
import type { Role } from "@/types";

const destinations: Record<Role, string> = { super_admin: "/super-admin", school_admin: "/school", teacher: "/teacher", student: "/student", parent: "/parent" };

export default function Home() {
  const { user, loading } = useAuth(); const router = useRouter();
  useEffect(() => { if (!loading) router.replace(user ? destinations[user.role] : "/login"); }, [loading, user, router]);
  return <div className="loading-screen"><div className="loading-spinner" /><span>Preparing your workspace...</span></div>;
}
