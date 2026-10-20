"use client";

import { useEffect, useMemo, useState } from "react";
import { api, ApiRequestError } from "@/lib/api";
import { EmptyState, ErrorState, LoadingState, Notice } from "@/components/admin/AdminStates";
import type { Attendance, TeacherAssignment, Student } from "@/types";

type AttendanceRow = { student: Student; status: Attendance["status"] };
type ClassSection = Pick<TeacherAssignment, "class_id" | "section_id" | "class" | "section">;

function localDate() {
  const date = new Date();
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

export default function TeacherAttendancePage() {
  const [assignments, setAssignments] = useState<TeacherAssignment[]>([]);
  const [selection, setSelection] = useState("");
  const [date, setDate] = useState(localDate);
  const [rows, setRows] = useState<AttendanceRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadingStudents, setLoadingStudents] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  useEffect(() => {
    const timer = window.setTimeout(() => {
      void api.get<{ assignments: TeacherAssignment[] }>("/teacher/assignments")
        .then(({ assignments: result }) => {
          setAssignments(result);
          const first = result[0];
          if (first) setSelection(`${first.class_id}:${first.section_id}`);
        })
        .catch((cause) => setError(cause instanceof ApiRequestError ? cause.message : "Unable to load your classes."))
        .finally(() => setLoading(false));
    }, 0);
    return () => window.clearTimeout(timer);
  }, []);

  const classSections = useMemo(() => assignments.reduce<ClassSection[]>((unique, item) => {
    if (!unique.some((entry) => entry.class_id === item.class_id && entry.section_id === item.section_id)) {
      unique.push(item);
    }
    return unique;
  }, []), [assignments]);
  const selected = classSections.find((item) => `${item.class_id}:${item.section_id}` === selection);

  useEffect(() => {
    if (!selected || !date) return;
    let active = true;
    const query = new URLSearchParams({
      class_id: String(selected.class_id),
      section_id: String(selected.section_id),
      date,
    });
    const timer = window.setTimeout(() => {
      setLoadingStudents(true);
      setRows([]);
      setError("");
      void api.get<{ students: Array<{ student: Student; attendance: Attendance | null }> }>(`/attendance/students?${query}`)
        .then(({ students }) => {
          if (active) setRows(students.map(({ student, attendance }) => ({
            student,
            status: attendance?.status ?? "present",
          })));
        })
        .catch((cause) => {
          if (!active) return;
          setRows([]);
          setError(cause instanceof ApiRequestError ? cause.message : "Unable to load the class roster.");
        })
        .finally(() => {
          if (active) setLoadingStudents(false);
        });
    }, 0);
    return () => {
      active = false;
      window.clearTimeout(timer);
    };
  }, [selected, date]);

  async function save() {
    if (!selected || rows.length === 0) return;
    setSaving(true);
    setError("");
    setNotice("");
    try {
      await api.post("/attendances/bulk", {
        class_id: selected.class_id,
        section_id: selected.section_id,
        attendance_date: date,
        records: rows.map(({ student, status }) => ({ student_id: student.id, status })),
      });
      setNotice("Attendance saved successfully.");
    } catch (cause) {
      setError(cause instanceof ApiRequestError ? cause.message : "Unable to save attendance.");
    } finally {
      setSaving(false);
    }
  }

  if (loading) return <LoadingState label="Loading your classes..." />;
  if (error && assignments.length === 0) return <ErrorState message={error} onRetry={() => window.location.reload()} />;

  return (
    <>
      <section className="admin-page-heading">
        <div><p className="kicker">Daily attendance</p><h2>Attendance</h2><p>Mark attendance for students in your assigned classes.</p></div>
      </section>
      {notice && <Notice message={notice} />}
      {error && <p className="field-error" role="alert">{error}</p>}
      <section className="list-card">
        {!classSections.length ? (
          <EmptyState title="No assigned classes" copy="Your school administrator must assign a class and section before you can take attendance." />
        ) : (
          <>
            <div className="form-grid">
              <label>Date<input type="date" value={date} onChange={(event) => setDate(event.target.value)} /></label>
              <label>Class and section<select value={selection} onChange={(event) => setSelection(event.target.value)}>
                {classSections.map((item) => {
                  const key = `${item.class_id}:${item.section_id}`;
                  return <option key={key} value={key}>{item.class?.name ?? `Class #${item.class_id}`} · {item.section?.name ?? `Section #${item.section_id}`}</option>;
                })}
              </select></label>
            </div>
            {loadingStudents ? <LoadingState label="Loading class roster..." /> : rows.length ? (
              <>
                <div className="data-table-wrap"><table className="data-table">
                  <thead><tr><th>Student ID</th><th>Student</th><th>Attendance</th></tr></thead>
                  <tbody>{rows.map(({ student, status }) => (
                    <tr key={student.id}>
                      <td>{student.student_id ?? `#${student.id}`}</td>
                      <td>{student.user?.name ?? "Unnamed student"}</td>
                      <td><select aria-label={`Attendance for ${student.user?.name ?? student.id}`} value={status} onChange={(event) => {
                        const value = event.target.value as Attendance["status"];
                        setRows((current) => current.map((row) => row.student.id === student.id ? { ...row, status: value } : row));
                      }}>
                        <option value="present">Present</option><option value="absent">Absent</option><option value="late">Late</option><option value="excused">Excused</option>
                      </select></td>
                    </tr>
                  ))}</tbody>
                </table></div>
                <div className="form-actions"><button className="primary-button" onClick={() => void save()} disabled={saving}>{saving ? "Saving..." : "Save attendance"}</button></div>
              </>
            ) : !error ? <EmptyState title="No students in this class" copy="There are no students enrolled in the selected section." /> : null}
          </>
        )}
      </section>
    </>
  );
}
