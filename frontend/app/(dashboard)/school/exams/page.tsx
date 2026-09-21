"use client";

import { useEffect, useState } from "react";
import { api, ApiRequestError } from "@/lib/api";
import { ErrorState, LoadingState, Notice } from "@/components/admin/AdminStates";
import type { AcademicYear, Exam, SchoolClass, Subject } from "@/types";

export default function ExamsPage() {
  const [exams, setExams] = useState<Exam[]>([]);
  const [years, setYears] = useState<AcademicYear[]>([]);
  const [classes, setClasses] = useState<SchoolClass[]>([]);
  const [subjects, setSubjects] = useState<Subject[]>([]);
  const [assignments, setAssignments] = useState<Array<{ school_class_id: number; subject_id: number }>>([]);
  const [form, setForm] = useState({ name: "", academic_year_id: "", class_id: "" });
  const [selectedSubjects, setSelectedSubjects] = useState<number[]>([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  useEffect(() => {
    Promise.all([
      api.get<{ exams: Exam[] }>("/exams"),
      api.get<{ academic_years: AcademicYear[] }>("/academic-years"),
      api.get<{ classes: SchoolClass[] }>("/classes"),
      api.get<{ subjects: Subject[] }>("/subjects"),
      api.get<{ assignments: Array<{ school_class_id: number; subject_id: number }> }>("/class-subject-assignments"),
    ]).then(([examResult, yearResult, classResult, subjectResult, assignmentResult]) => {
      setExams(examResult.exams); setYears(yearResult.academic_years); setClasses(classResult.classes);
      setSubjects(subjectResult.subjects); setAssignments(assignmentResult.assignments);
    }).catch((cause) => setError(cause instanceof ApiRequestError ? cause.message : "Unable to load exams."))
      .finally(() => setLoading(false));
  }, []);

  const availableSubjects = subjects.filter((subject) => assignments.some((assignment) => assignment.school_class_id === Number(form.class_id) && assignment.subject_id === subject.id));

  async function save(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true); setError(""); setNotice("");
    try {
      const result = await api.post<{ exam: Exam }>("/exams", { name: form.name, academic_year_id: Number(form.academic_year_id), class_id: Number(form.class_id), is_active: true });
      await Promise.all(selectedSubjects.map((subjectId) => api.post("/exam-subjects", { exam_id: result.exam.id, subject_id: subjectId, full_marks: 100, pass_marks: 33 })));
      setExams((current) => [{ ...result.exam, examSubjects: [] }, ...current]);
      setForm({ name: "", academic_year_id: "", class_id: "" }); setSelectedSubjects([]);
      setNotice("Exam created successfully.");
    } catch (cause) { setError(cause instanceof ApiRequestError ? cause.message : "Unable to create exam."); }
    finally { setBusy(false); }
  }

  if (loading) return <LoadingState label="Loading exams..." />;
  if (error && !exams.length) return <ErrorState message={error} onRetry={() => window.location.reload()} />;
  return <><section className="admin-page-heading"><div><p className="kicker">Assessment setup</p><h2>Exams</h2><p>Create an exam with subjects assigned to its class.</p></div></section>{notice && <Notice message={notice} />}{error && <p className="field-error">{error}</p>}<section className="list-card"><form className="school-form" onSubmit={save}><div className="form-grid"><label>Exam name<input value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} required /></label><label>Academic Year<select value={form.academic_year_id} onChange={(event) => setForm({ ...form, academic_year_id: event.target.value })} required><option value="">Select year</option>{years.map((year) => <option key={year.id} value={year.id}>{year.name}</option>)}</select></label><label>Class<select value={form.class_id} onChange={(event) => { setForm({ ...form, class_id: event.target.value }); setSelectedSubjects([]); }} required><option value="">Select class</option>{classes.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label></div><div className="form-grid">{availableSubjects.map((subject) => <label key={subject.id}><input type="checkbox" checked={selectedSubjects.includes(subject.id)} onChange={(event) => setSelectedSubjects((current) => event.target.checked ? [...current, subject.id] : current.filter((id) => id !== subject.id))} /> {subject.name} ({subject.code})</label>)}</div><button className="primary-button" disabled={busy || !selectedSubjects.length}>{busy ? "Saving..." : "Save exam"}</button></form></section><section className="list-card"><h3>Existing exams</h3>{exams.map((exam) => <div className="list-toolbar" key={exam.id}><strong>{exam.name}</strong><span>{exam.class?.name ?? "Class"} · {exam.academicYear?.name ?? "Academic year"}</span></div>)}</section></>;
}
