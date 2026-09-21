"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { Pencil, Plus, Search, Trash2 } from "lucide-react";
import { api, ApiRequestError } from "@/lib/api";
import { ConfirmDialog } from "@/components/admin/ConfirmDialog";
import { BulkAttendancePanel } from "@/components/admin/BulkAttendancePanel";
import { DataTable } from "@/components/admin/DataTable";
import { EmptyState, ErrorState, LoadingState, Notice } from "@/components/admin/AdminStates";
import type { Attendance, SchoolClass, Section, Student } from "@/types";

type AttendanceFormValues = {
  student_id: string;
  class_id: string;
  section_id: string;
  date: string;
  status: Attendance["status"];
  remarks: string;
};

const today = new Date().toISOString().slice(0, 10);
const emptyForm: AttendanceFormValues = { student_id: "", class_id: "", section_id: "", date: today, status: "present", remarks: "" };

export default function AttendancePage() {
  const [items, setItems] = useState<Attendance[]>([]);
  const [students, setStudents] = useState<Student[]>([]);
  const [classes, setClasses] = useState<SchoolClass[]>([]);
  const [sections, setSections] = useState<Section[]>([]);
  const [dateFilter, setDateFilter] = useState("");
  const [search, setSearch] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [modal, setModal] = useState(false);
  const [selected, setSelected] = useState<Attendance | null>(null);
  const [confirm, setConfirm] = useState<Attendance | null>(null);
  const [form, setForm] = useState<AttendanceFormValues>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState("");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const [attendanceResult, studentResult, classResult, sectionResult] = await Promise.all([
        api.get<{ attendances: Attendance[] }>(dateFilter ? `/attendances?date=${dateFilter}` : "/attendances"),
        api.get<{ students: Student[] }>("/students"),
        api.get<{ classes: SchoolClass[] }>("/classes"),
        api.get<{ sections: Section[] }>("/sections"),
      ]);
      setItems(attendanceResult.attendances);
      setStudents(studentResult.students);
      setClasses(classResult.classes);
      setSections(sectionResult.sections);
    } catch (cause) {
      setError(cause instanceof ApiRequestError ? cause.message : "Unable to load attendance.");
    } finally {
      setLoading(false);
    }
  }, [dateFilter]);

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  const filteredSections = sections.filter((section) => !form.class_id || String(section.school_class_id) === form.class_id);
  const filteredStudents = students.filter((student) => (!form.class_id || String(student.class_id) === form.class_id) && (!form.section_id || String(student.section_id) === form.section_id));
  const visible = useMemo(() => items.filter((item) => {
    const query = search.toLowerCase();
    const name = item.student?.user?.name ?? item.student?.student_id ?? "";
    return !query || name.toLowerCase().includes(query) || item.class?.name.toLowerCase().includes(query) || item.section?.name.toLowerCase().includes(query) || item.status.includes(query);
  }), [items, search]);

  function openCreate() {
    setSelected(null);
    setForm({ ...emptyForm });
    setErrors({});
    setModal(true);
  }

  function openEdit(item: Attendance) {
    setSelected(item);
    setForm({ student_id: String(item.student_id), class_id: String(item.class_id), section_id: String(item.section_id), date: item.date.slice(0, 10), status: item.status, remarks: item.remarks ?? "" });
    setErrors({});
    setModal(true);
  }

  async function save(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setErrors({});
    try {
      const payload = { ...form, student_id: Number(form.student_id), class_id: Number(form.class_id), section_id: Number(form.section_id) };
      const result = selected
        ? await api.put<{ attendance: Attendance }>(`/attendances/${selected.id}`, payload)
        : await api.post<{ attendance: Attendance }>("/attendances", payload);
      setItems((current) => selected ? current.map((item) => item.id === selected.id ? result.attendance : item) : [result.attendance, ...current]);
      setModal(false);
      setNotice(selected ? "Attendance updated successfully." : "Attendance saved successfully.");
    } catch (cause) {
      setErrors(cause instanceof ApiRequestError ? (cause.details?.errors ?? { _form: [cause.message] }) : { _form: ["Unable to save attendance."] });
    } finally {
      setBusy(false);
    }
  }

  async function remove() {
    if (!confirm) return;
    setBusy(true);
    try {
      await api.delete(`/attendances/${confirm.id}`);
      setItems((current) => current.filter((item) => item.id !== confirm.id));
      setConfirm(null);
      setNotice("Attendance deleted successfully.");
    } catch (cause) {
      setNotice(cause instanceof ApiRequestError ? cause.message : "Unable to delete attendance.");
    } finally {
      setBusy(false);
    }
  }

  if (loading) return <LoadingState label="Loading attendance..." />;
  if (error) return <ErrorState message={error} onRetry={() => void load()} />;

  return <><BulkAttendancePanel /><section className="admin-page-heading"><div><p className="kicker">School records</p><h2>Attendance history</h2><p>Review and manage saved attendance records.</p></div><button className="primary-button compact-button" onClick={openCreate}><Plus size={17} />Record one student</button></section>{notice && <Notice message={notice} />}<section className="list-card"><div className="list-toolbar"><label className="search-input"><Search size={17} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search student, class or status" aria-label="Search attendance" /></label><label className="filter-select"><span>Date</span><input type="date" value={dateFilter} onChange={(event) => setDateFilter(event.target.value)} aria-label="Filter attendance by date" /></label></div>{visible.length ? <DataTable columns={[{ label: "Student", key: "student" }, { label: "Class", key: "class" }, { label: "Date", key: "date" }, { label: "Status", key: "status" }, { label: "Actions", key: "actions" }]} rows={visible.map((item) => ({ ...item, student: <strong>{item.student?.user?.name ?? item.student?.student_id ?? `Student #${item.student_id}`}</strong>, class: `${item.class?.name ?? `Class #${item.class_id}`} / ${item.section?.name ?? `Section #${item.section_id}`}`, date: item.date.slice(0, 10), status: <span className={`status-badge ${item.status === "present" ? "status-active" : "status-inactive"}`}>{item.status}</span>, actions: <div className="row-actions"><button onClick={() => openEdit(item)} aria-label="Edit attendance"><Pencil size={16} /></button><button className="row-danger" onClick={() => setConfirm(item)} aria-label="Delete attendance"><Trash2 size={16} /></button></div> }))} /> : <EmptyState title="No attendance found" copy="Record attendance or adjust the search/date filter." />}</section>{modal && <div className="dialog-backdrop"><div className="form-dialog"><div className="dialog-header"><p className="kicker">{selected ? "Edit record" : "New record"}</p><h2>{selected ? "Edit attendance" : "Record attendance"}</h2></div><form className="school-form" onSubmit={save}><div className="form-grid"><label>Class<select value={form.class_id} onChange={(event) => setForm((current) => ({ ...current, class_id: event.target.value, section_id: "", student_id: "" }))} required><option value="">Select class</option>{classes.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label><label>Section<select value={form.section_id} onChange={(event) => setForm((current) => ({ ...current, class_id: current.class_id, section_id: event.target.value, student_id: "" }))} required><option value="">Select section</option>{filteredSections.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label><label>Student<select value={form.student_id} onChange={(event) => setForm((current) => ({ ...current, student_id: event.target.value }))} required><option value="">Select student</option>{filteredStudents.map((item) => <option key={item.id} value={item.id}>{item.user?.name ?? item.student_id ?? `Student #${item.id}`}</option>)}</select></label><label>Date<input type="date" value={form.date} onChange={(event) => setForm((current) => ({ ...current, date: event.target.value }))} required /></label><label>Status<select value={form.status} onChange={(event) => setForm((current) => ({ ...current, status: event.target.value as Attendance["status"] }))}>{["present", "absent", "late", "excused"].map((status) => <option key={status} value={status}>{status}</option>)}</select></label><label>Remarks<input value={form.remarks} onChange={(event) => setForm((current) => ({ ...current, remarks: event.target.value }))} /></label></div>{Object.entries(errors).map(([key, messages]) => <small className="field-error" key={key}>{messages[0]}</small>)}<div className="form-actions"><button type="button" className="secondary-button" onClick={() => setModal(false)}>Cancel</button><button className="primary-button" disabled={busy}>{busy ? "Saving..." : "Save attendance"}</button></div></form></div></div>}{confirm && <ConfirmDialog title="Delete this attendance record?" copy="This attendance record will be permanently removed." busy={busy} onCancel={() => setConfirm(null)} onConfirm={() => void remove()} />}</>;
}
