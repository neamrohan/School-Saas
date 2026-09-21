"use client";

import { useCallback, useEffect, useState } from "react";
import { ArrowLeft, GraduationCap, Mail, Phone, School as SchoolIcon, Users, UsersRound } from "lucide-react";
import { useParams, useRouter } from "next/navigation";
import { api, ApiRequestError } from "@/lib/api";
import { EmptyState, ErrorState, LoadingState } from "@/components/admin/AdminStates";
import { StatCard } from "@/components/admin/StatCard";
import type { School } from "@/types";

export default function SchoolDetailsPage() {
  const params = useParams<{ id: string }>(); const router = useRouter(); const [school, setSchool] = useState<School | null>(null); const [loading, setLoading] = useState(true); const [error, setError] = useState("");
  const load = useCallback(async () => { setLoading(true); setError(""); try { const result = await api.get<{ school: School }>(`/schools/${params.id}`); setSchool(result.school); } catch (cause) { setError(cause instanceof ApiRequestError ? cause.message : "Unable to load school details."); } finally { setLoading(false); } }, [params.id]);
  useEffect(() => { const timer = window.setTimeout(() => { void load(); }, 0); return () => window.clearTimeout(timer); }, [load]);
  if (loading) return <LoadingState label="Loading school details..." />;
  if (error) return <ErrorState message={error} onRetry={() => void load()} />;
  if (!school) return <EmptyState title="School not found" copy="This school may have been removed or is unavailable." />;
  const users = school.users ?? []; const count = (role: string) => users.filter((user) => user.role === role).length;
  return <><button className="back-link" onClick={() => router.push("/super-admin/schools")}><ArrowLeft size={16} />Back to schools</button><section className="detail-hero"><div className="detail-title"><span className="large-school-icon"><SchoolIcon size={25} /></span><div><p className="kicker">School profile</p><h2>{school.name}</h2><span className="code-text">{school.code}</span></div></div><span className={`status-badge ${school.is_active ? "status-active" : "status-inactive"}`}>{school.is_active ? "Active" : "Inactive"}</span></section><section className="admin-stat-grid detail-stats"><StatCard label="Users" value={users.length} icon={Users} tone="blue" /><StatCard label="Teachers" value={count("teacher")} icon={UsersRound} tone="mint" /><StatCard label="Students" value={count("student")} icon={GraduationCap} tone="coral" /><StatCard label="Parents" value={count("parent")} icon={Users} tone="gold" /></section><section className="detail-card"><div><p className="kicker">Contact</p><h3>School information</h3></div><div className="contact-grid"><div><Mail size={17} /><span>{school.email || "No email provided"}</span></div><div><Phone size={17} /><span>{school.phone || "No phone provided"}</span></div><div className="contact-wide"><SchoolIcon size={17} /><span>{school.address || "No address provided"}</span></div></div></section></>;
}
