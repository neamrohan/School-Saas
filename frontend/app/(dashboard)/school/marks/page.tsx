"use client";

import { useEffect, useMemo, useState } from "react";
import { api, ApiRequestError } from "@/lib/api";
import { EmptyState, ErrorState, LoadingState, Notice } from "@/components/admin/AdminStates";
import type { Exam, ExamSubject, SchoolClass, Section, Student } from "@/types";

type Row = { student: Student; mark: { marks: string } | null; value: string };

export default function MarksPage() {
  const [exams, setExams] = useState<Exam[]>([]); const [subjects, setSubjects] = useState<ExamSubject[]>([]); const [classes, setClasses] = useState<SchoolClass[]>([]); const [sections, setSections] = useState<Section[]>([]); const [rows, setRows] = useState<Row[]>([]);
  const [examId, setExamId] = useState(""); const [subjectId, setSubjectId] = useState(""); const [classId, setClassId] = useState(""); const [sectionId, setSectionId] = useState(""); const [loading, setLoading] = useState(true); const [busy, setBusy] = useState(false); const [error, setError] = useState(""); const [notice, setNotice] = useState("");
  useEffect(() => { Promise.all([api.get<{ exams: Exam[] }>("/exams"), api.get<{ exam_subjects: ExamSubject[] }>("/exam-subjects"), api.get<{ classes: SchoolClass[] }>("/classes"), api.get<{ sections: Section[] }>("/sections")]).then(([e, s, c, sec]) => { setExams(e.exams); setSubjects(s.exam_subjects); setClasses(c.classes); setSections(sec.sections); }).catch((cause) => setError(cause instanceof ApiRequestError ? cause.message : "Unable to load marks data.")).finally(() => setLoading(false)); }, []);
  const exam = exams.find((item) => String(item.id) === examId); const examSubjects = useMemo(() => subjects.filter((item) => item.exam_id === Number(examId)), [subjects, examId]); const availableSections = sections.filter((item) => String(item.school_class_id) === classId);
  useEffect(() => { if (!examId || !subjectId || !classId || !sectionId) return; const timer = window.setTimeout(() => { api.get<{ students: Array<{ student: Student; mark: { marks: string } | null }> }>(`/marks/students?exam_subject_id=${subjectId}&class_id=${classId}&section_id=${sectionId}`).then((result) => setRows(result.students.map((item) => ({ ...item, value: item.mark?.marks ?? "" })))).catch((cause) => setError(cause instanceof ApiRequestError ? cause.message : "Unable to load students.")); }, 0); return () => window.clearTimeout(timer); }, [examId, subjectId, classId, sectionId]);
  function selectExam(value: string) { const selected = exams.find((item) => String(item.id) === value); setExamId(value); setSubjectId(""); setClassId(selected?.class_id ? String(selected.class_id) : ""); setSectionId(""); }
  async function save() {
    if (!subjectId || !classId || !sectionId || rows.some((row) => row.value.trim() === "")) {
      setError("Select all filters and enter marks for every student."); return;
    }
    setBusy(true); setError("");
    try {
      const result = await api.post<{ marks: Array<{ student_id: number; marks: string }> }>("/marks/bulk", {
        exam_subject_id: Number(subjectId), class_id: Number(classId), section_id: Number(sectionId),
        records: rows.map((row) => ({ student_id: row.student.id, marks: Number(row.value) })),
      });
      setRows((current) => current.map((row) => ({ ...row, mark: result.marks.find((mark) => mark.student_id === row.student.id) ?? row.mark })));
      setNotice("Marks saved successfully.");
    } catch (cause) { setError(cause instanceof ApiRequestError ? cause.message : "Unable to save marks."); }
    finally { setBusy(false); }
  }
  if (loading) return <LoadingState label="Loading marks..." />; if (error && !exams.length) return <ErrorState message={error} onRetry={() => window.location.reload()} />;
  return <><section className="admin-page-heading"><div><p className="kicker">Assessment entry</p><h2>Marks</h2><p>Select an exam, subject, class, and section.</p></div></section>{notice && <Notice message={notice} />}{error && <p className="field-error">{error}</p>}<section className="list-card"><div className="form-grid"><label>Exam<select value={examId} onChange={(event) => selectExam(event.target.value)}><option value="">Select exam</option>{exams.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label><label>Subject<select value={subjectId} onChange={(event) => setSubjectId(event.target.value)} disabled={!examId}><option value="">Select subject</option>{examSubjects.map((item) => <option key={item.id} value={item.id}>{item.subject?.name}</option>)}</select></label><label>Class<select value={classId} onChange={(event) => { setClassId(event.target.value); setSectionId(""); }} disabled={!exam}><option value="">Select class</option>{classes.filter((item) => !exam?.class_id || item.id === exam.class_id).map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label><label>Section<select value={sectionId} onChange={(event) => setSectionId(event.target.value)} disabled={!classId}><option value="">Select section</option>{availableSections.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label></div>{rows.length ? <><div className="list-toolbar"><strong>Student</strong><strong>Marks</strong></div>{rows.map((row) => <div className="list-toolbar" key={row.student.id}><span>{row.student.student_id ?? `#${row.student.id}`} · {row.student.user?.name}</span><input type="number" min="0" max={subjects.find((item) => item.id === Number(subjectId))?.full_marks ?? undefined} value={row.value} onChange={(event) => setRows((current) => current.map((item) => item.student.id === row.student.id ? { ...item, value: event.target.value } : item))} /></div>)}<button className="primary-button" onClick={() => void save()} disabled={busy}>{busy ? "Saving..." : "Save marks"}</button></> : <EmptyState title="No students loaded" copy="Choose all four filters to load the class roster." />}</section></>;
}
