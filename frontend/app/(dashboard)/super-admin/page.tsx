"use client";

import { useCallback, useEffect, useState } from "react";
import { Building2, CircleCheck, CircleX, GraduationCap, ShieldCheck, Users, UsersRound } from "lucide-react";
import { api, ApiRequestError } from "@/lib/api";
import { DataTable } from "@/components/admin/DataTable";
import { EmptyState, ErrorState, LoadingState } from "@/components/admin/AdminStates";
import { StatCard } from "@/components/admin/StatCard";
import type { SuperAdminDashboard } from "@/types";

export default function SuperAdminPage() {
  const [dashboard, setDashboard] = useState<SuperAdminDashboard | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const load = useCallback(async () => { setLoading(true); setError(""); try { const result = await api.get<{ dashboard: SuperAdminDashboard }>("/super-admin/dashboard"); setDashboard(result.dashboard); } catch (cause) { setError(cause instanceof ApiRequestError ? cause.message : "Unable to reach the dashboard service."); } finally { setLoading(false); } }, []);
  useEffect(() => { const timer = window.setTimeout(() => { void load(); }, 0); return () => window.clearTimeout(timer); }, [load]);
  if (loading) return <LoadingState label="Loading network overview..." />;
  if (error) return <ErrorState message={error} onRetry={() => void load()} />;
  if (!dashboard) return <EmptyState title="No dashboard data" copy="There is no network data to display yet." />;
  const stats = [{ label: "Total schools", value: dashboard.total_schools, icon: Building2, tone: "coral" as const }, { label: "Active schools", value: dashboard.active_schools, icon: CircleCheck, tone: "mint" as const }, { label: "Inactive schools", value: dashboard.inactive_schools, icon: CircleX, tone: "gold" as const }, { label: "Total users", value: dashboard.total_users, icon: Users, tone: "blue" as const }, { label: "Teachers", value: dashboard.total_teachers, icon: UsersRound, tone: "mint" as const }, { label: "Students", value: dashboard.total_students, icon: GraduationCap, tone: "coral" as const }, { label: "Parents", value: dashboard.total_parents, icon: Users, tone: "gold" as const }];
  return <><section className="admin-hero"><div><p className="kicker">System overview</p><h2>Every school, clearly seen.</h2><p>Monitor the network without losing sight of the people inside it.</p></div><div className="admin-hero-mark"><ShieldCheck size={25} /><span>Live network data</span></div></section><section className="admin-stat-grid">{stats.map((stat) => <StatCard key={stat.label} {...stat} />)}</section><section className="admin-section"><div className="section-heading"><div><p className="kicker">Network directory</p><h3>School summary</h3></div><span>{dashboard.schools.length} schools</span></div>{dashboard.schools.length ? <DataTable columns={[{ label: "School", key: "name" }, { label: "Code", key: "code" }, { label: "Students", key: "students" }, { label: "Teachers", key: "teachers" }, { label: "Status", key: "status" }]} rows={dashboard.schools.map((school) => ({ ...school, code: school.code ?? "—", students: school.student_count, teachers: school.teacher_count, status: <span className={`status-badge ${school.is_active ? "status-active" : "status-inactive"}`}>{school.is_active ? "Active" : "Inactive"}</span> }))} /> : <EmptyState title="No schools yet" copy="Create the first school to start your network." />}</section></>;
}
