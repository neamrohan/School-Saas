"use client";

import { useEffect, useMemo, useState } from "react";
import { api, ApiRequestError } from "@/lib/api";
import { EmptyState, ErrorState, LoadingState, Notice } from "@/components/admin/AdminStates";
import type { ExamSubject, Mark, Student, TeacherAssignment } from "@/types";

type MarkRow = { key: string; student: Student; mark: Mark | null; value: string };

export default function TeacherMarksPage() {
  const [assignments, setAssignments] = useState<TeacherAssignment[]>([]);
  const [examSubjects, setExamSubjects] = useState<ExamSubject[]>([]);
  const [assignmentId, setAssignmentId] = useState("");
  const [examSubjectId, setExamSubjectId] = useState("");
  const [rows, setRows] = useState<MarkRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadingStudents, setLoadingStudents] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  useEffect(() => {
    const timer = window.setTimeout(() => {
      void Promise.all([
        api.get<{ assignments: TeacherAssignment[] }>("/teacher/assignments"),
        api.get<{ exam_subjects: ExamSubject[] }>("/teacher/exam-subjects"),
      ]).then(([assignmentResult, subjectResult]) => {
        setAssignments(assignmentResult.assignments);
        setExamSubjects(subjectResult.exam_subjects);
        const first = assignmentResult.assignments[0];
        if (first) setAssignmentId(String(first.id));
      }).catch((cause) => setError(cause instanceof ApiRequestError ? cause.message : "Unable to load assessment data."))
        .finally(() => setLoading(false));
    }, 0);
    return () => window.clearTimeout(timer);
  }, []);

  const assignment = assignments.find((item) => String(item.id) === assignmentId);
  const availableExamSubjects = useMemo(() => examSubjects.filter((item) =>
    item.exam?.class_id === assignment?.class_id && item.subject_id === assignment?.subject_id,
  ), [examSubjects, assignment]);
  const effectiveExamSubjectId = availableExamSubjects.some((item) => String(item.id) === examSubjectId)
    ? examSubjectId
    : String(availableExamSubjects[0]?.id ?? "");
  const rowKey = `${assignmentId}:${effectiveExamSubjectId}`;
  const visibleRows = rows.filter((row) => row.key === rowKey);

  useEffect(() => {
    if (!assignment || !effectiveExamSubjectId) return;
    let active = true;
    const query = new URLSearchParams({
      exam_subject_id: effectiveExamSubjectId,
      class_id: String(assignment.class_id),
      section_id: String(assignment.section_id),
    });
    const timer = window.setTimeout(() => {
      setLoadingStudents(true);
      setRows([]);
      setError("");
      void api.get<{ students: Array<{ student: Student; mark: Mark | null }> }>(`/marks/students?${query}`)
        .then(({ students }) => {
          if (active) setRows(students.map(({ student, mark }) => ({
            key: rowKey,
            student,
            mark,
            value: mark?.marks ?? "",
          })));
        })
        .catch((cause) => {
          if (!active) return;
          setRows([]);
          setError(cause instanceof ApiRequestError ? cause.message : "Unable to load students.");
        })
        .finally(() => {
          if (active) setLoadingStudents(false);
        });
    }, 0);
    return () => {
      active = false;
      window.clearTimeout(timer);
    };
  }, [assignment, effectiveExamSubjectId, rowKey]);

  const selectedExamSubject = availableExamSubjects.find((item) => String(item.id) === effectiveExamSubjectId);

  async function save() {
    if (!assignment || !selectedExamSubject || visibleRows.length === 0 || visibleRows.some((row) => row.value.trim() === "")) {
      setError("Enter marks for every student before saving.");
      return;
    }
    setSaving(true);
    setError("");
    setNotice("");
    try {
      const result = await api.post<{ marks: Mark[] }>("/marks/bulk", {
        exam_subject_id: selectedExamSubject.id,
        class_id: assignment.class_id,
        section_id: assignment.section_id,
        records: visibleRows.map(({ student, value }) => ({ student_id: student.id, marks: Number(value) })),
      });
      setRows((current) => current.map((row) => row.key !== rowKey ? row : ({
        ...row,
        mark: result.marks.find((item) => item.student_id === row.student.id) ?? row.mark,
      })));
      setNotice("Marks saved successfully.");
    } catch (cause) {
      setError(cause instanceof ApiRequestError ? cause.message : "Unable to save marks.");
    } finally {
      setSaving(false);
    }
  }

  if (loading) return <LoadingState label="Loading your assessments..." />;
  if (error && assignments.length === 0) return <ErrorState message={error} onRetry={() => window.location.reload()} />;

  return (
    <>
      <section className="admin-page-heading">
        <div><p className="kicker">Assessment entry</p><h2>Marks</h2><p>Enter marks for subjects and classes assigned to you.</p></div>
      </section>
      {notice && <Notice message={notice} />}
      {error && <p className="field-error" role="alert">{error}</p>}
      <section className="list-card">
        {!assignments.length ? (
          <EmptyState title="No assigned subjects" copy="Your school administrator must assign a subject before you can enter marks." />
        ) : (
          <>
            <div className="form-grid">
              <label>Class, section and subject<select value={assignmentId} onChange={(event) => { setAssignmentId(event.target.value); setError(""); }}>
                {assignments.map((item) => <option key={item.id} value={item.id}>
                  {item.class?.name ?? `Class #${item.class_id}`} · {item.section?.name ?? `Section #${item.section_id}`} · {item.subject?.name ?? `Subject #${item.subject_id}`}
                </option>)}
              </select></label>
              <label>Exam<select value={effectiveExamSubjectId} disabled={!availableExamSubjects.length} onChange={(event) => { setExamSubjectId(event.target.value); setError(""); }}>
                {availableExamSubjects.length ? availableExamSubjects.map((item) => <option key={item.id} value={item.id}>
                  {item.exam?.name ?? `Exam #${item.exam_id}`}
                </option>) : <option value="">No exam for this assignment</option>}
              </select></label>
            </div>
            {loadingStudents && effectiveExamSubjectId ? <LoadingState label="Loading students..." /> : visibleRows.length ? (
              <>
                <div className="data-table-wrap"><table className="data-table">
                  <thead><tr><th>Student ID</th><th>Student</th><th>Marks / {selectedExamSubject?.full_marks}</th></tr></thead>
                  <tbody>{visibleRows.map(({ student, value }) => (
                    <tr key={student.id}>
                      <td>{student.student_id ?? `#${student.id}`}</td>
                      <td>{student.user?.name ?? "Unnamed student"}</td>
                      <td><input
                        type="number"
                        min="0"
                        max={selectedExamSubject?.full_marks}
                        step="0.01"
                        aria-label={`Marks for ${student.user?.name ?? student.id}`}
                        value={value}
                        onChange={(event) => setRows((current) => current.map((row) =>
                          row.key === rowKey && row.student.id === student.id ? { ...row, value: event.target.value } : row,
                        ))}
                      /></td>
                    </tr>
                  ))}</tbody>
                </table></div>
                <div className="form-actions"><button className="primary-button" onClick={() => void save()} disabled={saving}>{saving ? "Saving..." : "Save marks"}</button></div>
              </>
            ) : availableExamSubjects.length && !error ? (
              <EmptyState title="No students in this section" copy="There are no students enrolled in the selected class and section." />
            ) : !error ? (
              <EmptyState title="No exam available" copy="There are no exam subjects set up for this class and assigned subject yet." />
            ) : null}
          </>
        )}
      </section>
    </>
  );
}
