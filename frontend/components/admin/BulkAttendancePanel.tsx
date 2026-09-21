"use client";

import { useEffect, useMemo, useState } from "react";
import { api, ApiRequestError } from "@/lib/api";
import { DataTable } from "@/components/admin/DataTable";
import { EmptyState, LoadingState, Notice } from "@/components/admin/AdminStates";
import type { Attendance, SchoolClass, Section, Student } from "@/types";

type RosterRow = {
  student: Student;
  attendance: Attendance | null;
  status: "present" | "absent";
};

export function BulkAttendancePanel() {
  const [classes, setClasses] = useState<SchoolClass[]>([]);
  const [sections, setSections] = useState<Section[]>([]);
  const [rows, setRows] = useState<RosterRow[]>([]);
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
  const [classId, setClassId] = useState("");
  const [sectionId, setSectionId] = useState("");
  const [loading, setLoading] = useState(true);
  const [loadingStudents, setLoadingStudents] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  useEffect(() => {
    Promise.all([
      api.get<{ classes: SchoolClass[] }>("/classes"),
      api.get<{ sections: Section[] }>("/sections"),
    ]).then(([classResult, sectionResult]) => {
      setClasses(classResult.classes);
      setSections(sectionResult.sections);
    }).catch((cause) => {
      setError(cause instanceof ApiRequestError ? cause.message : "Unable to load classes and sections.");
    }).finally(() => setLoading(false));
  }, []);

  const availableSections = useMemo(
    () => sections.filter((section) => String(section.school_class_id) === classId),
    [sections, classId],
  );

  useEffect(() => {
    if (!classId || !sectionId || !date) {
      return;
    }

    const controller = new AbortController();
    const timer = window.setTimeout(() => {
      setLoadingStudents(true);
      setError("");
      api.get<{ students: Array<{ student: Student; attendance: Attendance | null }> }>(
        `/attendance/students?class_id=${classId}&section_id=${sectionId}&date=${date}`,
      ).then((result) => {
        setRows(result.students.map(({ student, attendance }) => ({
          student,
          attendance,
          status: attendance?.status === "absent" ? "absent" : "present",
        })));
      }).catch((cause) => {
        if (!controller.signal.aborted) {
          setRows([]);
          setError(cause instanceof ApiRequestError ? cause.message : "Unable to load students.");
        }
      }).finally(() => {
        if (!controller.signal.aborted) setLoadingStudents(false);
      });
    }, 0);

    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [classId, sectionId, date]);

  function updateStatus(studentId: number, status: RosterRow["status"]) {
    setRows((current) => current.map((row) => row.student.id === studentId ? { ...row, status } : row));
  }

  async function save() {
    if (!classId || !sectionId || !date || !rows.length) return;
    setSaving(true);
    setError("");
    setNotice("");
    try {
      await api.post("/attendances/bulk", {
        class_id: Number(classId),
        section_id: Number(sectionId),
        attendance_date: date,
        records: rows.map((row) => ({
          student_id: row.student.id,
          status: row.status,
        })),
      });
      setNotice("Attendance saved successfully.");
      const result = await api.get<{ students: Array<{ student: Student; attendance: Attendance | null }> }>(
        `/attendance/students?class_id=${classId}&section_id=${sectionId}&date=${date}`,
      );
      setRows(result.students.map(({ student, attendance }) => ({
        student,
        attendance,
        status: attendance?.status === "absent" ? "absent" : "present",
      })));
    } catch (cause) {
      setError(cause instanceof ApiRequestError ? cause.message : "Unable to save attendance.");
    } finally {
      setSaving(false);
    }
  }

  if (loading) return <LoadingState label="Loading attendance filters..." />;

  return (
    <section className="list-card">
      <div className="admin-page-heading">
        <div>
          <p className="kicker">Daily attendance</p>
          <h2>Class attendance</h2>
          <p>Select a date, class, and section to mark every student.</p>
        </div>
      </div>
      {notice && <Notice message={notice} />}
      {error && <p className="field-error">{error}</p>}
      <div className="form-grid">
        <label>Date<input type="date" value={date} onChange={(event) => setDate(event.target.value)} /></label>
        <label>Class<select value={classId} onChange={(event) => { setClassId(event.target.value); setSectionId(""); setRows([]); }}><option value="">Select class</option>{classes.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
        <label>Section<select value={sectionId} disabled={!classId} onChange={(event) => { setSectionId(event.target.value); setRows([]); }}><option value="">Select section</option>{availableSections.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
      </div>
      {loadingStudents ? <LoadingState label="Loading students..." /> : classId && sectionId && !rows.length ? <EmptyState title="No students found for this class and section." copy="Choose another class or section." /> : rows.length ? <><DataTable columns={[{ label: "Student ID", key: "student_id" }, { label: "Student Name", key: "student_name" }, { label: "Attendance", key: "attendance" }]} rows={rows.map((row) => ({ id: row.student.id, student_id: row.student.student_id ?? `#${row.student.id}`, student_name: row.student.user?.name ?? "Unnamed student", attendance: <div className="row-actions"><button type="button" className={row.status === "present" ? "primary-button compact-button" : "secondary-button"} onClick={() => updateStatus(row.student.id, "present")}>✓ Present</button><button type="button" className={row.status === "absent" ? "primary-button compact-button" : "secondary-button"} onClick={() => updateStatus(row.student.id, "absent")}>✗ Absent</button></div> }))} /><div className="form-actions"><button type="button" className="primary-button" onClick={() => void save()} disabled={saving}>{saving ? "Saving..." : "Save Attendance"}</button></div></> : <p>Select a class and section to load students.</p>}
    </section>
  );
}
