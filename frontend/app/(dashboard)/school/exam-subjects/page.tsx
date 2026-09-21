"use client";

import { useEffect, useMemo, useState } from "react";
import { api, ApiRequestError } from "@/lib/api";
import { EmptyState, ErrorState, LoadingState, Notice } from "@/components/admin/AdminStates";
import type { Exam, ExamSubject, Subject, ClassSubjectAssignment } from "@/types";

export default function ExamSubjectsPage() {
  const [exams, setExams] = useState<Exam[]>([]);
  const [subjects, setSubjects] = useState<Subject[]>([]);
  const [assignments, setAssignments] = useState<ClassSubjectAssignment[]>([]);
  const [examSubjects, setExamSubjects] = useState<ExamSubject[]>([]);
  const [examId, setExamId] = useState("");
  const [subjectId, setSubjectId] = useState("");
  const [fullMarks, setFullMarks] = useState("100");
  const [passMarks, setPassMarks] = useState("33");
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  useEffect(() => {
    Promise.all([
      api.get<{ exams: Exam[] }>("/exams"),
      api.get<{ subjects: Subject[] }>("/subjects"),
      api.get<{ assignments: ClassSubjectAssignment[] }>("/class-subject-assignments"),
      api.get<{ exam_subjects: ExamSubject[] }>("/exam-subjects"),
    ]).then(([examResult, subjectResult, assignmentResult, examSubjectResult]) => {
      setExams(examResult.exams);
      setSubjects(subjectResult.subjects);
      setAssignments(assignmentResult.assignments);
      setExamSubjects(examSubjectResult.exam_subjects);
    }).catch((cause) => setError(cause instanceof ApiRequestError ? cause.message : "Unable to load exam subjects."))
      .finally(() => setLoading(false));
  }, []);

  const exam = exams.find((item) => String(item.id) === examId);
  const assignedToClass = useMemo(
    () => subjects.filter((subject) => assignments.some((assignment) =>
      assignment.school_class_id === exam?.class_id && assignment.subject_id === subject.id)),
    [assignments, exam, subjects],
  );
  const currentAssignments = examSubjects.filter((item) => item.exam_id === Number(examId));
  const availableSubjects = assignedToClass.filter((subject) => !currentAssignments.some((item) => item.subject_id === subject.id));

  async function addSubject(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true); setError(""); setNotice("");
    try {
      const result = await api.post<{ exam_subject: ExamSubject }>("/exam-subjects", {
        exam_id: Number(examId), subject_id: Number(subjectId),
        full_marks: Number(fullMarks), pass_marks: Number(passMarks),
      });
      setExamSubjects((current) => [result.exam_subject, ...current]);
      setSubjectId(""); setNotice("Subject added to exam.");
    } catch (cause) {
      setError(cause instanceof ApiRequestError ? cause.message : "Unable to add subject.");
    } finally { setBusy(false); }
  }

  if (loading) return <LoadingState label="Loading exam subjects..." />;
  if (error && !exams.length) return <ErrorState message={error} onRetry={() => window.location.reload()} />;
  return <>
    <section className="admin-page-heading"><div><p className="kicker">Assessment setup</p><h2>Exam Subject</h2><p>Add only subjects assigned to the exam&apos;s class.</p></div></section>
    {notice && <Notice message={notice} />}{error && <p className="field-error">{error}</p>}
    <section className="list-card"><form className="school-form" onSubmit={addSubject}>
      <div className="form-grid">
        <label>Exam<select value={examId} onChange={(event) => { setExamId(event.target.value); setSubjectId(""); }} required><option value="">Select exam</option>{exams.map((item) => <option key={item.id} value={item.id}>{item.name} · {item.class?.name ?? "Class"}</option>)}</select></label>
        <label>Subject<select value={subjectId} onChange={(event) => setSubjectId(event.target.value)} disabled={!examId || !availableSubjects.length} required><option value="">Select subject</option>{availableSubjects.map((item) => <option key={item.id} value={item.id}>{item.name} ({item.code})</option>)}</select></label>
        <label>Full marks<input type="number" min="0" value={fullMarks} onChange={(event) => setFullMarks(event.target.value)} required /></label>
        <label>Pass marks<input type="number" min="0" value={passMarks} onChange={(event) => setPassMarks(event.target.value)} required /></label>
      </div>
      <button className="primary-button" disabled={busy || !subjectId}>{busy ? "Adding..." : "Add subject"}</button>
    </form></section>
    <section className="list-card"><h3>Assigned subjects</h3>{currentAssignments.length ? currentAssignments.map((item) => <div className="list-toolbar" key={item.id}><span>{item.subject?.name}</span><span>{item.full_marks} / pass {item.pass_marks}</span></div>) : <EmptyState title="No subjects assigned" copy="Select an exam and add a class-assigned subject." />}</section>
  </>;
}
