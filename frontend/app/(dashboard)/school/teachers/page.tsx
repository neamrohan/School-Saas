"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { Pencil, Power, Plus, Search } from "lucide-react";
import { api, ApiRequestError } from "@/lib/api";
import { EmptyState, ErrorState, LoadingState, Notice } from "@/components/admin/AdminStates";
import { DataTable } from "@/components/admin/DataTable";
import { TeacherForm, type TeacherFormValues } from "@/components/admin/TeacherForm";
import type { SchoolClass, Section, Subject, Teacher } from "@/types";

export default function TeachersPage() {
  const [teachers, setTeachers] = useState<Teacher[]>([]);
  const [classes, setClasses] = useState<SchoolClass[]>([]);
  const [sections, setSections] = useState<Section[]>([]);
  const [subjects, setSubjects] = useState<Subject[]>([]);
  const [search, setSearch] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [selected, setSelected] = useState<Teacher | null>(null);
  const [modal, setModal] = useState(false);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]> | undefined>();
  const [notice, setNotice] = useState("");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const [teacherResult, classResult, sectionResult, subjectResult] = await Promise.all([
        api.get<{ teachers: Teacher[] }>("/teachers"),
        api.get<{ classes: SchoolClass[] }>("/classes"),
        api.get<{ sections: Section[] }>("/sections"),
        api.get<{ subjects: Subject[] }>("/subjects"),
      ]);
      setTeachers(teacherResult.teachers);
      setClasses(classResult.classes);
      setSections(sectionResult.sections);
      setSubjects(subjectResult.subjects);
    } catch (cause) {
      setError(cause instanceof ApiRequestError ? cause.message : "Unable to load teachers.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  const filtered = useMemo(() => {
    const query = search.toLowerCase();
    return teachers.filter((teacher) => [teacher.user?.name, teacher.user?.email, teacher.user?.username, teacher.employee_id]
      .some((value) => value?.toLowerCase().includes(query)));
  }, [teachers, search]);

  const openCreate = () => { setSelected(null); setErrors(undefined); setModal(true); };
  const openEdit = (teacher: Teacher) => { setSelected(teacher); setErrors(undefined); setModal(true); };

  async function save(values: TeacherFormValues) {
    setBusy(true);
    setErrors(undefined);
    const data = new FormData();
    Object.entries(values).forEach(([key, value]) => {
      if (key === "profile_photo") {
        if (value instanceof File) data.append(key, value);
      } else if (typeof value === "boolean") {
        data.append(key, value ? "1" : "0");
      } else {
        data.append(key, value ?? "");
      }
    });
    if (selected) data.append("_method", "PUT");

    try {
      if (selected) {
        const result = await api.postForm<{ teacher: Teacher }>(`/teachers/${selected.id}`, data);
        setTeachers((current) => current.map((item) => item.id === selected.id ? result.teacher : item));
        setNotice("Teacher account updated successfully.");
      } else {
        const result = await api.postForm<{ teacher: Teacher }>("/teachers", data);
        setTeachers((current) => [result.teacher, ...current]);
        setNotice("Teacher account created successfully.");
      }
      setModal(false);
    } catch (cause) {
      setErrors({ ...(cause instanceof ApiRequestError ? cause.details?.errors : {}), _form: [cause instanceof ApiRequestError ? cause.message : "Unable to save teacher account."] });
    } finally {
      setBusy(false);
    }
  }

  async function toggleStatus(teacher: Teacher) {
    const isActive = teacher.user?.is_active !== false;
    try {
      const result = await api.patch<{ teacher: Teacher }>(`/teachers/${teacher.id}`, { is_active: !isActive });
      setTeachers((current) => current.map((item) => item.id === teacher.id ? result.teacher : item));
      setNotice(`Teacher account ${isActive ? "deactivated" : "activated"}.`);
    } catch (cause) {
      setNotice(cause instanceof ApiRequestError ? cause.message : "Unable to update teacher status.");
    }
  }

  if (loading) return <LoadingState label="Loading teachers..." />;
  if (error) return <ErrorState message={error} onRetry={() => void load()} />;

  return <>
    <section className="admin-page-heading"><div><p className="kicker">People directory</p><h2>Teachers</h2><p>Manage teacher accounts, profile details, and class assignments.</p></div><button className="primary-button compact-button" onClick={openCreate}><Plus size={17} />Add teacher</button></section>
    {notice && <Notice message={notice} />}
    <section className="list-card">
      <div className="list-toolbar"><label className="search-input"><Search size={17} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search name, email, username, or Teacher ID" aria-label="Search teachers" /></label></div>
      {filtered.length ? <DataTable columns={[{ label: "Teacher", key: "teacher" }, { label: "Teacher ID", key: "employee" }, { label: "Phone", key: "phone" }, { label: "Designation", key: "designation" }, { label: "Status", key: "status" }, { label: "Actions", key: "actions" }]} rows={filtered.map((teacher) => {
        const isActive = teacher.user?.is_active !== false;
        return {
          ...teacher,
          teacher: <div className="school-cell"><span className="school-avatar">{teacher.user?.name.charAt(0).toUpperCase()}</span><div><strong>{teacher.user?.name ?? "Unnamed teacher"}</strong><small>{teacher.user?.email || teacher.user?.username || "No sign-in identifier"}</small></div></div>,
          employee: <span className="code-text">{teacher.employee_id || "—"}</span>,
          phone: teacher.phone || "—",
          designation: teacher.designation || "—",
          status: <span className={`status-badge ${isActive ? "status-active" : "status-inactive"}`}>{isActive ? "Active" : "Inactive"}</span>,
          actions: <div className="row-actions"><button onClick={() => openEdit(teacher)} aria-label="Edit teacher"><Pencil size={16} /></button><button className={isActive ? "row-danger" : ""} onClick={() => void toggleStatus(teacher)} aria-label={isActive ? "Deactivate teacher" : "Activate teacher"} title={isActive ? "Deactivate teacher" : "Activate teacher"}><Power size={16} /></button></div>,
        };
      })} /> : <EmptyState title="No teachers found" copy={teachers.length ? "Try a different search." : "Create a teacher account to begin."} />}
    </section>
    {modal && <div className="dialog-backdrop"><div className="form-dialog"><div className="dialog-header"><p className="kicker">{selected ? "Edit account" : "New account"}</p><h2>{selected ? "Edit teacher" : "Add a teacher"}</h2></div><TeacherForm teacher={selected} classes={classes} sections={sections} subjects={subjects} busy={busy} errors={errors} onSubmit={save} onCancel={() => setModal(false)} /></div></div>}
  </>;
}