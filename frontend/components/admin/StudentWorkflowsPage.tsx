"use client";

import { useCallback, useEffect, useState, type FormEvent } from "react";
import Link from "next/link";
import { useAuth } from "@/providers/AuthProvider";
import { api, ApiRequestError } from "@/lib/api";
import { EmptyState, ErrorState, LoadingState, Notice } from "@/components/admin/AdminStates";
import { DataTable } from "@/components/admin/DataTable";
import type { AcademicYear, SchoolClass, Section, Student } from "@/types";

type Workflow = "online-admissions" | "re-admissions" | "bulk-profile" | "waiver-types" | "student-waivers" | "waiver-history";
type Application = {
  id: number; name: string; email: string; phone?: string; guardian_name: string; guardian_phone: string;
  status: string; review_note?: string; created_at: string; academic_year?: AcademicYear; class?: SchoolClass;
  section?: Section; approved_student?: Student;
};
type WaiverType = { id: number; name: string; description?: string | null; calculation_type: "percentage" | "fixed"; value: string; is_active: boolean; waivers_count?: number };
type Fee = { id: number; amount: string; status: string; fee_type?: { name: string }; fee_item?: { name: string }; waivers?: Array<{ amount: string; status: string }> };
type Waiver = { id: number; amount: string; status: string; reason?: string | null; assigned_at: string; student?: Student; student_fee?: Fee; waiver_type?: WaiverType; assigned_by?: { name: string }; revoked_at?: string };
type ReAdmission = { id: number; created_at: string; student?: Student; previous_academic_year?: AcademicYear; academic_year?: AcademicYear };
type Paginator<T> = { data: T[]; current_page: number; last_page: number; total: number };

const headings: Record<Workflow, string> = {
  "online-admissions": "Online Admission Applications",
  "re-admissions": "Re-Admission",
  "bulk-profile": "Bulk Profile Update",
  "waiver-types": "Waiver Type",
  "student-waivers": "Student Waiver",
  "waiver-history": "Waiver History",
};

function displayStudent(student?: Student): string {
  return student?.user?.name ?? student?.student_id ?? (student ? `Student ${student.id}` : "—");
}

export default function StudentWorkflowsPage({ workflow }: { workflow: Workflow }) {
  const { user } = useAuth();
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [applications, setApplications] = useState<Application[]>([]);
  const [students, setStudents] = useState<Student[]>([]);
  const [classes, setClasses] = useState<SchoolClass[]>([]);
  const [sections, setSections] = useState<Section[]>([]);
  const [years, setYears] = useState<AcademicYear[]>([]);
  const [waiverTypes, setWaiverTypes] = useState<WaiverType[]>([]);
  const [fees, setFees] = useState<Fee[]>([]);
  const [feesStudentId, setFeesStudentId] = useState("");
  const [waivers, setWaivers] = useState<Waiver[]>([]);
  const [reAdmissions, setReAdmissions] = useState<ReAdmission[]>([]);
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [studentId, setStudentId] = useState("");
  const [feeId, setFeeId] = useState("");
  const [typeId, setTypeId] = useState("");
  const [search, setSearch] = useState("");
  const [typeForm, setTypeForm] = useState({ name: "", description: "", calculation_type: "percentage", value: "" });
  const [profileForm, setProfileForm] = useState({ phone: "", address: "", gender: "", class_id: "", section_id: "", academic_year_id: "", is_active: "" });
  const [reAdmissionForm, setReAdmissionForm] = useState({ academic_year_id: "", class_id: "", section_id: "" });
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      if (workflow === "online-admissions") {
        const result = await api.get<{ applications: Paginator<Application> }>(`/online-admissions?page=${page}`);
        setApplications(result.applications.data);
        setLastPage(result.applications.last_page);
      } else if (workflow === "re-admissions" || workflow === "bulk-profile") {
        const studentQuery = new URLSearchParams({ paginate: "1", per_page: "100" });
        if (search.trim()) studentQuery.set("search", search.trim());
        const [studentResult, classResult, yearResult, sectionResult] = await Promise.all([
          api.get<{ students: Student[] | Paginator<Student> }>(`/students?${studentQuery.toString()}`),
          api.get<{ classes: SchoolClass[] }>("/classes"),
          api.get<{ academic_years: AcademicYear[] }>("/academic-years"),
          api.get<{ sections: Section[] }>("/sections"),
        ]);
        const studentData = studentResult.students;
        setStudents(Array.isArray(studentData) ? studentData : studentData.data);
        setClasses(classResult.classes);
        setYears(yearResult.academic_years);
        setSections(sectionResult.sections);
        if (workflow === "re-admissions") {
          const result = await api.get<{ re_admissions: Paginator<ReAdmission> }>(`/student-re-admissions?page=${page}`);
          setReAdmissions(result.re_admissions.data);
          setLastPage(result.re_admissions.last_page);
        }
      } else if (workflow === "waiver-types") {
        const result = await api.get<{ waiver_types: Paginator<WaiverType> }>("/waiver-types");
        setWaiverTypes(result.waiver_types.data);
      } else {
        const [studentResult, typeResult, waiverResult] = await Promise.all([
          api.get<{ students: Student[] | Paginator<Student> }>("/students?paginate=1&per_page=100"),
          api.get<{ waiver_types: Paginator<WaiverType> }>("/waiver-types"),
          api.get<{ waivers: Paginator<Waiver> }>(`/student-waivers?page=${page}`),
        ]);
        const studentData = studentResult.students;
        setStudents(Array.isArray(studentData) ? studentData : studentData.data);
        setWaiverTypes(typeResult.waiver_types.data.filter((item) => item.is_active));
        setWaivers(waiverResult.waivers.data);
        setLastPage(waiverResult.waivers.last_page);
      }
    } catch (cause) {
      setError(cause instanceof ApiRequestError ? cause.message : `Unable to load ${headings[workflow].toLowerCase()}.`);
    } finally {
      setLoading(false);
    }
  }, [page, search, workflow]);

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  useEffect(() => {
    if (workflow !== "student-waivers" || !studentId) {
      return;
    }
    let active = true;
    api.get<{ student_fees: Fee[] }>(`/student-fees?student_id=${studentId}`)
      .then((result) => {
        if (active) {
          setFees(result.student_fees);
          setFeesStudentId(studentId);
        }
      })
      .catch((cause) => { if (active) setError(cause instanceof ApiRequestError ? cause.message : "Unable to load student fees."); });
    return () => { active = false; };
  }, [studentId, workflow]);

  async function review(application: Application, decision: "approve" | "reject") {
    const reviewNote = decision === "reject" ? window.prompt("Reason for rejection (optional):") : null;
    if (decision === "reject" && reviewNote === null) return;
    if (!window.confirm(`${decision === "approve" ? "Approve" : "Reject"} the application from ${application.name}?`)) return;
    setBusy(true);
    setError("");
    try {
      const result = await api.patch<{ message: string; temporary_password: string | null }>(`/online-admissions/${application.id}/review`, { decision, review_note: reviewNote });
      setNotice(result.temporary_password
        ? `${result.message} One-time temporary password: ${result.temporary_password}. Share it securely; it cannot be retrieved again.`
        : result.message);
      await load();
    } catch (cause) {
      setError(cause instanceof ApiRequestError ? cause.message : "Unable to review this application.");
    } finally {
      setBusy(false);
    }
  }

  async function reAdmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const student = students.find((item) => String(item.id) === studentId);
    if (!student) return;
    setBusy(true);
    setError("");
    try {
      const result = await api.post<{ message: string }>(`/students/${student.id}/re-admit`, {
        academic_year_id: Number(reAdmissionForm.academic_year_id),
        class_id: reAdmissionForm.class_id ? Number(reAdmissionForm.class_id) : null,
        section_id: reAdmissionForm.section_id ? Number(reAdmissionForm.section_id) : null,
      });
      setNotice(result.message);
      setReAdmissionForm({ academic_year_id: "", class_id: "", section_id: "" });
      await load();
    } catch (cause) {
      setError(cause instanceof ApiRequestError ? cause.message : "Unable to re-admit this student.");
    } finally {
      setBusy(false);
    }
  }

  async function bulkUpdate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!selectedIds.length) return;
    const updates: Record<string, string | boolean | number | null> = {};
    Object.entries(profileForm).forEach(([key, value]) => {
      if (value === "") return;
      updates[key] = key === "is_active" ? value === "true" : ["class_id", "section_id", "academic_year_id"].includes(key) ? Number(value) : value;
    });
    if (profileForm.class_id && !profileForm.section_id) updates.section_id = null;
    if (!Object.keys(updates).length) {
      setError("Select at least one profile field to update.");
      return;
    }
    if (!window.confirm(`Apply the selected changes to ${selectedIds.length} student(s)?`)) return;
    setBusy(true);
    setError("");
    try {
      const result = await api.post<{ message: string; updated: number }>("/students/bulk-update", { student_ids: selectedIds, ...updates });
      setNotice(`${result.message} (${result.updated} students)`);
      setSelectedIds([]);
      setProfileForm({ phone: "", address: "", gender: "", class_id: "", section_id: "", academic_year_id: "", is_active: "" });
      await load();
    } catch (cause) {
      setError(cause instanceof ApiRequestError ? cause.message : "Unable to update student profiles.");
    } finally {
      setBusy(false);
    }
  }

  async function saveWaiverType(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setError("");
    try {
      const result = await api.post<{ waiver_type: WaiverType }>("/waiver-types", { ...typeForm, value: typeForm.value });
      setWaiverTypes((current) => [result.waiver_type, ...current]);
      setTypeForm({ name: "", description: "", calculation_type: "percentage", value: "" });
      setNotice("Waiver type created.");
    } catch (cause) {
      setError(cause instanceof ApiRequestError ? cause.message : "Unable to save this waiver type.");
    } finally {
      setBusy(false);
    }
  }

  async function toggleWaiverType(type: WaiverType) {
    try {
      const result = await api.put<{ waiver_type: WaiverType }>(`/waiver-types/${type.id}`, { is_active: !type.is_active });
      setWaiverTypes((current) => current.map((item) => item.id === type.id ? result.waiver_type : item));
    } catch (cause) {
      setError(cause instanceof ApiRequestError ? cause.message : "Unable to update waiver type.");
    }
  }

  async function assignWaiver(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setError("");
    try {
      const result = await api.post<{ message: string }>("/student-waivers", {
        student_id: Number(studentId),
        student_fee_id: Number(feeId),
        waiver_type_id: Number(typeId),
      });
      setNotice(result.message);
      setStudentId("");
      setFeeId("");
      setTypeId("");
      await load();
    } catch (cause) {
      setError(cause instanceof ApiRequestError ? cause.message : "Unable to apply this waiver.");
    } finally {
      setBusy(false);
    }
  }

  async function revokeWaiver(waiver: Waiver) {
    if (!window.confirm(`Revoke the ${waiver.amount} waiver for ${displayStudent(waiver.student)}? The action will remain in history.`)) return;
    try {
      await api.post(`/student-waivers/${waiver.id}/revoke`, {});
      setNotice("Waiver revoked and retained in history.");
      await load();
    } catch (cause) {
      setError(cause instanceof ApiRequestError ? cause.message : "Unable to revoke this waiver.");
    }
  }

  if (loading) return <LoadingState label={`Loading ${headings[workflow].toLowerCase()}...`} />;
  if (error && !students.length && !applications.length && !waiverTypes.length && !waivers.length) {
    return <ErrorState message={error} onRetry={() => void load()} />;
  }

  const filteredStudents = students.filter((student) => !search
    || displayStudent(student).toLowerCase().includes(search.toLowerCase())
    || student.student_id?.toLowerCase().includes(search.toLowerCase()));
  const selectedStudent = students.find((student) => String(student.id) === studentId);
  const availableFees = workflow === "student-waivers" && studentId === feesStudentId ? fees : [];

  return <>
    <section className="admin-page-heading"><div><p className="kicker">Student Information</p><h2>{headings[workflow]}</h2><p>Manage school records using your existing student and finance data.</p></div></section>
    {notice && <Notice message={notice} />}
    {error && <p className="field-error" role="alert">{error}</p>}
    {workflow === "online-admissions" && <>
      {user?.school?.code && <p className="report-limitation">Public application link: <Link href={`/admission/${encodeURIComponent(user.school.code)}`} target="_blank" rel="noreferrer">Open online admission form</Link></p>}
      <section className="list-card"><h3>Applications</h3>{applications.length ? <DataTable columns={[
        { label: "Applicant", key: "applicant" }, { label: "Guardian", key: "guardian" }, { label: "Session / class", key: "placement" }, { label: "Submitted", key: "submitted" }, { label: "Status", key: "status" }, { label: "Review", key: "actions" },
      ]} rows={applications.map((application) => ({
        id: application.id,
        applicant: <div><strong>{application.name}</strong><small>{application.email} · {application.phone ?? "No phone"}</small></div>,
        guardian: `${application.guardian_name} · ${application.guardian_phone}`,
        placement: `${application.academic_year?.name ?? "—"} / ${application.class?.name ?? "—"}`,
        submitted: new Date(application.created_at).toLocaleDateString(),
        status: application.status,
        actions: application.status === "pending" ? <div className="row-actions"><button className="secondary-button" disabled={busy} onClick={() => void review(application, "approve")}>Approve</button><button className="danger-button" disabled={busy} onClick={() => void review(application, "reject")}>Reject</button></div> : application.review_note ?? "Reviewed",
      }))} /> : <EmptyState title="No admission applications" copy="Online applications submitted for this school will appear here." />}</section>
    </>}
    {workflow === "re-admissions" && <>
      <section className="list-card"><h3>Re-admit student into an academic session</h3><label className="search-input"><input value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} placeholder="Search student name or ID" aria-label="Search students" /></label><form className="school-form" onSubmit={reAdmit}><div className="form-grid">
      <label>Student<select value={studentId} onChange={(event) => setStudentId(event.target.value)} required><option value="">Select student</option>{filteredStudents.map((student) => <option key={student.id} value={student.id}>{student.student_id ?? student.id} · {displayStudent(student)}</option>)}</select></label>
      <label>New academic session<select value={reAdmissionForm.academic_year_id} onChange={(event) => setReAdmissionForm({ ...reAdmissionForm, academic_year_id: event.target.value })} required><option value="">Select session</option>{years.map((year) => <option key={year.id} value={year.id}>{year.name}</option>)}</select></label>
      <label>Class<select value={reAdmissionForm.class_id} onChange={(event) => setReAdmissionForm({ ...reAdmissionForm, class_id: event.target.value, section_id: "" })}><option value="">Unassigned</option>{classes.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
      <label>Section<select value={reAdmissionForm.section_id} onChange={(event) => setReAdmissionForm({ ...reAdmissionForm, section_id: event.target.value })} disabled={!reAdmissionForm.class_id}><option value="">Unassigned</option>{sections.filter((section) => String(section.school_class_id) === reAdmissionForm.class_id).map((section) => <option key={section.id} value={section.id}>{section.name}</option>)}</select></label>
      </div>{selectedStudent && <p className="report-filter-hint">Current session: {selectedStudent.academic_year?.name ?? "Not assigned"}</p>}<button className="primary-button" disabled={busy}>{busy ? "Saving..." : "Re-admit student"}</button></form></section>
      <section className="list-card"><h3>Re-admission history</h3>{reAdmissions.length ? <DataTable columns={[
        { label: "Student", key: "student" }, { label: "Previous session", key: "previous" }, { label: "New session", key: "current" }, { label: "Date", key: "date" },
      ]} rows={reAdmissions.map((record) => ({
        id: record.id,
        student: displayStudent(record.student),
        previous: record.previous_academic_year?.name ?? "—",
        current: record.academic_year?.name ?? "—",
        date: new Date(record.created_at).toLocaleDateString(),
      }))} /> : <EmptyState title="No re-admission history" copy="Completed re-admissions will appear here." />}</section>
    </>}
    {workflow === "bulk-profile" && <section className="list-card"><h3>Choose students and preview profile changes</h3><div className="report-filter-form"><label className="search-input"><input value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} placeholder="Search student name or ID" aria-label="Search students" /></label><p className="report-filter-hint">{selectedIds.length} selected. Maximum 100 records per update.</p><div className="bulk-student-list">{filteredStudents.map((student) => <label key={student.id} className="bulk-student-option"><input type="checkbox" checked={selectedIds.includes(student.id)} disabled={!selectedIds.includes(student.id) && selectedIds.length >= 100} onChange={(event) => setSelectedIds((current) => event.target.checked ? [...current, student.id] : current.filter((id) => id !== student.id))} /><span>{displayStudent(student)} · {student.student_id ?? student.id}</span></label>)}</div></div><form className="school-form report-filter-form" onSubmit={bulkUpdate}><h3>Changes to apply</h3><p className="report-filter-hint">Leave fields blank to keep each student’s existing value. Changing class clears section unless a new section is selected.</p><div className="form-grid">
      <label>Phone<input value={profileForm.phone} onChange={(event) => setProfileForm({ ...profileForm, phone: event.target.value })} /></label><label>Address<input value={profileForm.address} onChange={(event) => setProfileForm({ ...profileForm, address: event.target.value })} /></label><label>Gender<input value={profileForm.gender} onChange={(event) => setProfileForm({ ...profileForm, gender: event.target.value })} /></label>
      <label>Class<select value={profileForm.class_id} onChange={(event) => setProfileForm({ ...profileForm, class_id: event.target.value, section_id: "" })}><option value="">No change</option>{classes.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
      <label>Section<select value={profileForm.section_id} onChange={(event) => setProfileForm({ ...profileForm, section_id: event.target.value })}><option value="">No change / clear if class changes</option>{sections.filter((section) => !profileForm.class_id || String(section.school_class_id) === profileForm.class_id).map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
      <label>Academic session<select value={profileForm.academic_year_id} onChange={(event) => setProfileForm({ ...profileForm, academic_year_id: event.target.value })}><option value="">No change</option>{years.map((year) => <option key={year.id} value={year.id}>{year.name}</option>)}</select></label>
      <label>Account status<select value={profileForm.is_active} onChange={(event) => setProfileForm({ ...profileForm, is_active: event.target.value })}><option value="">No change</option><option value="true">Active</option><option value="false">Inactive</option></select></label>
    </div><div className="report-filter-actions"><button className="primary-button" disabled={busy || selectedIds.length === 0}>{busy ? "Updating..." : `Update ${selectedIds.length} student(s)`}</button><button className="secondary-button" type="button" onClick={() => setSelectedIds([])}>Clear selection</button></div></form></section>}
    {workflow === "waiver-types" && <><section className="list-card"><h3>Create waiver type</h3><form className="school-form report-filter-form" onSubmit={saveWaiverType}><div className="form-grid">
      <label>Name<input value={typeForm.name} onChange={(event) => setTypeForm({ ...typeForm, name: event.target.value })} required /></label><label>Calculation<select value={typeForm.calculation_type} onChange={(event) => setTypeForm({ ...typeForm, calculation_type: event.target.value })}><option value="percentage">Percentage</option><option value="fixed">Fixed amount</option></select></label><label>{typeForm.calculation_type === "percentage" ? "Percent (up to 100)" : "Amount"}<input value={typeForm.value} onChange={(event) => setTypeForm({ ...typeForm, value: event.target.value })} inputMode="decimal" required /></label><label>Description<input value={typeForm.description} onChange={(event) => setTypeForm({ ...typeForm, description: event.target.value })} /></label>
    </div><button className="primary-button" disabled={busy}>{busy ? "Saving..." : "Create waiver type"}</button></form></section><section className="list-card"><h3>Waiver types</h3>{waiverTypes.length ? <DataTable columns={[{ label: "Name", key: "name" }, { label: "Rule", key: "rule" }, { label: "Assigned", key: "assigned" }, { label: "Status", key: "status" }, { label: "Action", key: "action" }]} rows={waiverTypes.map((item) => ({ ...item, name: item.name, rule: `${item.calculation_type === "percentage" ? `${item.value}%` : item.value} · ${item.description ?? ""}`, assigned: item.waivers_count ?? 0, status: item.is_active ? "Active" : "Inactive", action: <button className="secondary-button" onClick={() => void toggleWaiverType(item)}>{item.is_active ? "Deactivate" : "Activate"}</button> }))} /> : <EmptyState title="No waiver types" copy="Create a waiver type before applying a student waiver." />}</section></>}
    {workflow === "student-waivers" && <section className="list-card"><h3>Apply waiver to a student fee</h3><form className="school-form report-filter-form" onSubmit={assignWaiver}><div className="form-grid">
      <label>Student<select value={studentId} onChange={(event) => { setStudentId(event.target.value); setFeeId(""); }} required><option value="">Select student</option>{students.map((student) => <option key={student.id} value={student.id}>{student.student_id ?? student.id} · {displayStudent(student)}</option>)}</select></label>
      <label>Fee<select value={feeId} onChange={(event) => setFeeId(event.target.value)} required disabled={!studentId}><option value="">Select fee</option>{availableFees.map((fee) => <option key={fee.id} value={fee.id}>{fee.fee_item?.name ?? fee.fee_type?.name ?? "Fee"} · {fee.amount} · {fee.status}</option>)}</select></label>
      <label>Waiver type<select value={typeId} onChange={(event) => setTypeId(event.target.value)} required><option value="">Select type</option>{waiverTypes.map((type) => <option key={type.id} value={type.id}>{type.name} · {type.calculation_type === "percentage" ? `${type.value}%` : type.value}</option>)}</select></label>
    </div><button className="primary-button" disabled={busy || !waiverTypes.length}>{busy ? "Applying..." : "Apply waiver"}</button></form></section>}
    {workflow === "student-waivers" && <section className="list-card"><h3>Recent waiver history</h3>{waivers.length ? <WaiverTable waivers={waivers} onRevoke={(waiver) => void revokeWaiver(waiver)} /> : <EmptyState title="No waiver history" copy="Applied waivers will appear here." />}</section>}
    {workflow === "waiver-history" && <section className="list-card"><h3>Waiver history</h3>{waivers.length ? <WaiverTable waivers={waivers} onRevoke={(waiver) => void revokeWaiver(waiver)} /> : <EmptyState title="No waiver history" copy="Applied and revoked waivers will appear here." />}</section>}
    {(workflow === "re-admissions" || workflow === "waiver-history" || workflow === "student-waivers" || workflow === "online-admissions") && <div className="report-filter-actions"><button className="secondary-button" disabled={page <= 1} onClick={() => setPage((current) => current - 1)}>Previous</button><span className="report-filter-hint">Page {page} of {lastPage}</span><button className="secondary-button" disabled={page >= lastPage} onClick={() => setPage((current) => current + 1)}>Next page</button></div>}
  </>;
}

function WaiverTable({ waivers, onRevoke }: { waivers: Waiver[]; onRevoke: (waiver: Waiver) => void }) {
  return <DataTable columns={[
    { label: "Student", key: "student" }, { label: "Fee", key: "fee" }, { label: "Waiver", key: "type" }, { label: "Amount", key: "amount" }, { label: "Assigned", key: "assigned" }, { label: "Status", key: "status" }, { label: "Action", key: "action" },
  ]} rows={waivers.map((waiver) => ({
    id: waiver.id,
    student: displayStudent(waiver.student),
    fee: waiver.student_fee?.fee_item?.name ?? waiver.student_fee?.fee_type?.name ?? "—",
    type: waiver.waiver_type?.name ?? "—",
    amount: waiver.amount,
    assigned: waiver.assigned_by?.name ? `${waiver.assigned_by.name} · ${new Date(waiver.assigned_at).toLocaleDateString()}` : new Date(waiver.assigned_at).toLocaleDateString(),
    status: waiver.status,
    action: waiver.status === "active" ? <button className="danger-button" onClick={() => onRevoke(waiver)}>Revoke</button> : waiver.revoked_at ? `Revoked ${new Date(waiver.revoked_at).toLocaleDateString()}` : "—",
  }))} />;
}
