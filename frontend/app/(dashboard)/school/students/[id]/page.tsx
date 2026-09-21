"use client";

import { useCallback, useEffect, useState } from "react";
import { ArrowLeft, CalendarCheck, CircleDollarSign, GraduationCap, Mail, School as SchoolIcon, UserRound } from "lucide-react";
import { useParams, useRouter } from "next/navigation";
import { api, ApiRequestError } from "@/lib/api";
import { EmptyState, ErrorState, LoadingState } from "@/components/admin/AdminStates";
import type { Student } from "@/types";

export default function StudentDetailsPage() {
  const { id } = useParams<{ id: string }>(); const router = useRouter(); const [student, setStudent] = useState<Student | null>(null); const [loading, setLoading] = useState(true); const [error, setError] = useState("");
  const load = useCallback(async () => { setLoading(true); setError(""); try { const result = await api.get<{ student: Student }>(`/students/${id}`); setStudent(result.student); } catch (cause) { setError(cause instanceof ApiRequestError ? cause.message : "Unable to load student details."); } finally { setLoading(false); } }, [id]);
  useEffect(() => { const timer = window.setTimeout(() => void load(), 0); return () => window.clearTimeout(timer); }, [load]);
  if (loading) return <LoadingState label="Loading student details..." />; if (error) return <ErrorState message={error} onRetry={() => void load()} />; if (!student) return <EmptyState title="Student not found" copy="This profile is unavailable." />;
  return <><button className="back-link" onClick={() => router.push("/school/students")}><ArrowLeft size={16} />Back to students</button><section className="detail-hero"><div className="detail-title"><span className="large-school-icon"><GraduationCap size={25} /></span><div><p className="kicker">Student profile</p><h2>{student.user?.name ?? "Student"}</h2><span className="code-text">{student.student_id || "No student ID"}</span></div></div><span className="status-badge status-active">Enrolled</span></section><section className="detail-card"><p className="kicker">Placement</p><h3>School information</h3><div className="contact-grid"><div><SchoolIcon size={17} /><span>{student.school?.name || "Current school"}</span></div><div><GraduationCap size={17} /><span>{student.class?.name || "Unassigned class"} · {student.section?.name || "No section"}</span></div><div><Mail size={17} /><span>{student.user?.email || "No email"}</span></div><div><UserRound size={17} /><span>{student.gender || "Gender not provided"}</span></div><div className="contact-wide"><span>Admission date: {student.admission_date?.slice(0, 10) || "Not provided"}</span></div></div></section><section className="student-links"><button onClick={() => router.push(`/school/attendance?student_id=${student.id}`)}><CalendarCheck size={18} />Attendance</button><button onClick={() => router.push(`/school/exams?student_id=${student.id}`)}><GraduationCap size={18} />Results</button><button onClick={() => router.push(`/school/fees?student_id=${student.id}`)}><CircleDollarSign size={18} />Fees</button></section></>;
}
