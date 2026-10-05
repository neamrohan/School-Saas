"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { Filter, Pencil, Power, Plus, Search } from "lucide-react";
import { api, ApiRequestError } from "@/lib/api";
import { EmptyState, ErrorState, LoadingState, Notice } from "@/components/admin/AdminStates";
import { DataTable } from "@/components/admin/DataTable";
import { StudentForm, type StudentFormValues } from "@/components/admin/StudentForm";
import type { AcademicYear, GroupTrade, SchoolClass, Section, Shift, Student, Version } from "@/types";

export default function StudentsPage() {
  const [students, setStudents] = useState<Student[]>([]);
  const [classes, setClasses] = useState<SchoolClass[]>([]);
  const [sections, setSections] = useState<Section[]>([]);
  const [academicYears, setAcademicYears] = useState<AcademicYear[]>([]);
  const [shifts, setShifts] = useState<Shift[]>([]);
  const [versions, setVersions] = useState<Version[]>([]);
  const [groups, setGroups] = useState<GroupTrade[]>([]);
  const [search, setSearch] = useState("");
  const [classFilter, setClassFilter] = useState("");
  const [sectionFilter, setSectionFilter] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [selected, setSelected] = useState<Student | null>(null);
  const [modal, setModal] = useState(false);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]> | undefined>();
  const [notice, setNotice] = useState("");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const [studentResult, classResult, sectionResult, yearResult, shiftResult, versionResult, groupResult] = await Promise.all([
        api.get<{ students: Student[] }>("/students"),
        api.get<{ classes: SchoolClass[] }>("/classes"),
        api.get<{ sections: Section[] }>("/sections"),
        api.get<{ academic_years: AcademicYear[] }>("/academic-years"),
        api.get<{ shifts: Shift[] }>("/shifts"),
        api.get<{ versions: Version[] }>("/versions"),
        api.get<{ groups: GroupTrade[] }>("/groups"),
      ]);
      setStudents(studentResult.students);
      setClasses(classResult.classes);
      setSections(sectionResult.sections);
      setAcademicYears(yearResult.academic_years);
      setShifts(shiftResult.shifts);
      setVersions(versionResult.versions);
      setGroups(groupResult.groups);
    } catch (cause) {
      setError(cause instanceof ApiRequestError ? cause.message : "Unable to load students.");
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
    return students.filter((student) => [student.user?.name, student.user?.email, student.user?.username, student.student_id]
      .some((value) => value?.toLowerCase().includes(query))
      && (!classFilter || String(student.class_id) === classFilter)
      && (!sectionFilter || String(student.section_id) === sectionFilter));
  }, [students, search, classFilter, sectionFilter]);

  const openCreate = () => { setSelected(null); setErrors(undefined); setModal(true); };
  const openEdit = (student: Student) => { setSelected(student); setErrors(undefined); setModal(true); };

  async function save(values: StudentFormValues) {
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
        const result = await api.postForm<{ student: Student }>(`/students/${selected.id}`, data);
        setStudents((current) => current.map((item) => item.id === selected.id ? result.student : item));
        setNotice("Student account updated successfully.");
      } else {
        const result = await api.postForm<{ student: Student }>("/students", data);
        setStudents((current) => [result.student, ...current]);
        setNotice("Student account created successfully.");
      }
      setModal(false);
    } catch (cause) {
      setErrors({ ...(cause instanceof ApiRequestError ? cause.details?.errors : {}), _form: [cause instanceof ApiRequestError ? cause.message : "Unable to save student account."] });
    } finally {
      setBusy(false);
    }
  }

  async function toggleStatus(student: Student) {
    const isActive = student.user?.is_active !== false && student.is_active !== false;
    try {
      const result = await api.patch<{ student: Student }>(`/students/${student.id}`, { is_active: !isActive });
      setStudents((current) => current.map((item) => item.id === student.id ? result.student : item));
      setNotice(`Student account ${isActive ? "deactivated" : "activated"}.`);
    } catch (cause) {
      setNotice(cause instanceof ApiRequestError ? cause.message : "Unable to update student status.");
    }
  }

  if (loading) return <LoadingState label="Loading students..." />;
  if (error) return <ErrorState message={error} onRetry={() => void load()} />;

  return <>
    <section className="admin-page-heading"><div><p className="kicker">People directory</p><h2>Students</h2><p>Manage student accounts, enrollment, and school placement.</p></div><button className="primary-button compact-button" onClick={openCreate}><Plus size={17} />Add student</button></section>
    {notice && <Notice message={notice} />}
    <section className="list-card">
      <div className="list-toolbar">
        <label className="search-input"><Search size={17} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search name, email, username, or Student ID" aria-label="Search students" /></label>
        <label className="filter-select"><Filter size={16} /><select value={classFilter} onChange={(event) => { setClassFilter(event.target.value); setSectionFilter(""); }} aria-label="Filter by class"><option value="">All classes</option>{classes.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
        <label className="filter-select"><select value={sectionFilter} onChange={(event) => setSectionFilter(event.target.value)} aria-label="Filter by section"><option value="">All sections</option>{sections.filter((section) => !classFilter || String(section.school_class_id) === classFilter).map((section) => <option key={section.id} value={section.id}>{section.name}</option>)}</select></label>
      </div>
      {filtered.length ? <DataTable columns={[{ label: "Student", key: "student" }, { label: "Student ID", key: "student_code" }, { label: "Class / section", key: "placement" }, { label: "Roll", key: "roll" }, { label: "Status", key: "status" }, { label: "Actions", key: "actions" }]} rows={filtered.map((student) => {
        const isActive = student.user?.is_active !== false && student.is_active !== false;
        return {
          ...student,
          student: <div className="school-cell"><span className="school-avatar">{student.user?.name.charAt(0).toUpperCase()}</span><div><strong>{student.user?.name ?? "Unnamed student"}</strong><small>{student.user?.email || student.user?.username || "No sign-in identifier"}</small></div></div>,
          student_code: <span className="code-text">{student.student_id || "—"}</span>,
          placement: `${student.class?.name ?? "Unassigned"} / ${student.section?.name ?? "—"}`,
          roll: student.roll || "—",
          status: <span className={`status-badge ${isActive ? "status-active" : "status-inactive"}`}>{isActive ? "Active" : "Inactive"}</span>,
          actions: <div className="row-actions"><button onClick={() => openEdit(student)} aria-label="Edit student"><Pencil size={16} /></button><button className={isActive ? "row-danger" : ""} onClick={() => void toggleStatus(student)} aria-label={isActive ? "Deactivate student" : "Activate student"} title={isActive ? "Deactivate student" : "Activate student"}><Power size={16} /></button></div>,
        };
      })} /> : <EmptyState title="No students found" copy={students.length ? "Try a different search or filter." : "Create a student account to begin."} />}
    </section>
    {modal && <div className="dialog-backdrop"><div className="form-dialog"><div className="dialog-header"><p className="kicker">{selected ? "Edit account" : "New account"}</p><h2>{selected ? "Edit student" : "Add a student"}</h2></div><StudentForm student={selected} classes={classes} sections={sections} academicYears={academicYears} shifts={shifts} versions={versions} groups={groups} busy={busy} errors={errors} onSubmit={save} onCancel={() => setModal(false)} /></div></div>}
  </>;
}