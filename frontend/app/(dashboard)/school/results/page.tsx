"use client";

import { useEffect, useState } from "react";
import { api, ApiRequestError } from "@/lib/api";
import { EmptyState, ErrorState, LoadingState } from "@/components/admin/AdminStates";
import type { Exam, Result, SchoolClass, Section } from "@/types";

export default function ResultsPage() {
  const [exams, setExams] = useState<Exam[]>([]);
  const [classes, setClasses] = useState<SchoolClass[]>([]);
  const [sections, setSections] = useState<Section[]>([]);
  const [results, setResults] = useState<Result[]>([]);
  const [examId, setExamId] = useState("");
  const [classId, setClassId] = useState("");
  const [sectionId, setSectionId] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    Promise.all([
      api.get<{ exams: Exam[] }>("/exams"),
      api.get<{ classes: SchoolClass[] }>("/classes"),
      api.get<{ sections: Section[] }>("/sections"),
    ]).then(([examResult, classResult, sectionResult]) => {
      setExams(examResult.exams); setClasses(classResult.classes); setSections(sectionResult.sections);
    }).catch((cause) => setError(cause instanceof ApiRequestError ? cause.message : "Unable to load results."))
      .finally(() => setLoading(false));
  }, []);

  const exam = exams.find((item) => String(item.id) === examId);
  const availableSections = sections.filter((item) => String(item.school_class_id) === classId);

  function selectExam(value: string) {
    const selected = exams.find((item) => String(item.id) === value);
    setExamId(value); setClassId(selected?.class_id ? String(selected.class_id) : ""); setSectionId(""); setResults([]);
  }

  async function loadResults() {
    if (!examId || !classId || !sectionId) return;
    setError("");
    try {
      const response = await api.get<{ results: Result[] }>(`/results?exam_id=${examId}&class_id=${classId}&section_id=${sectionId}`);
      setResults(response.results);
    } catch (cause) { setError(cause instanceof ApiRequestError ? cause.message : "Unable to load results."); }
  }

  if (loading) return <LoadingState label="Loading results..." />;
  if (error && !exams.length) return <ErrorState message={error} onRetry={() => window.location.reload()} />;
  return <>
    <section className="admin-page-heading"><div><p className="kicker">Assessment results</p><h2>Results</h2><p>View subject marks and calculated totals for a class section.</p></div></section>
    {error && <p className="field-error">{error}</p>}
    <section className="list-card"><div className="form-grid">
      <label>Exam<select value={examId} onChange={(event) => selectExam(event.target.value)} required><option value="">Select exam</option>{exams.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
      <label>Class<select value={classId} onChange={(event) => { setClassId(event.target.value); setSectionId(""); setResults([]); }} disabled={!exam} required><option value="">Select class</option>{classes.filter((item) => item.id === exam?.class_id).map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
      <label>Section<select value={sectionId} onChange={(event) => setSectionId(event.target.value)} disabled={!classId} required><option value="">Select section</option>{availableSections.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
    </div><button className="primary-button" onClick={() => void loadResults()} disabled={!examId || !classId || !sectionId}>Load results</button></section>
    {results.length ? results.map((result) => <section className="list-card" key={result.student.id}><h3>{result.student.user?.name} · {result.student.student_id ?? `#${result.student.id}`}</h3>{result.subjects.map((subject) => <div className="list-toolbar" key={subject.subject.id}><span>{subject.subject.name}</span><span>{subject.obtained_marks ?? "—"} / {subject.full_marks} · {subject.grade ?? "—"} · {subject.passed ? "Passed" : "Not passed"}</span></div>)}<p><strong>Total:</strong> {result.obtained_marks} / {result.total_marks} · <strong>Average:</strong> {result.average}% · <strong>GPA:</strong> {result.overall_gpa ?? "—"} · <strong>Result:</strong> {result.passed ? "Passed" : "Not passed"}</p></section>) : <EmptyState title="No results loaded" copy="Choose an exam, class, and section." />}
  </>;
}
