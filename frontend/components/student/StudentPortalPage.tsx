"use client";

import { useEffect, useState } from "react";
import { api, ApiRequestError } from "@/lib/api";
import { DataTable } from "@/components/admin/DataTable";
import { EmptyState, ErrorState, LoadingState } from "@/components/admin/AdminStates";
import type { Attendance, Mark, Routine, Student, Subject } from "@/types";

type PortalPage = "classes" | "attendance" | "results" | "routine" | "fees";
type PortalFee = {
  id: number;
  amount: string;
  status: string;
  due_date?: string | null;
  feeType?: { name: string } | null;
  fee_type?: { name: string } | null;
  feeItem?: { name: string } | null;
  fee_item?: { name: string } | null;
  payments?: Array<{ amount: string }>;
};
type PortalResponse = {
  student?: Student;
  subjects?: Subject[];
  attendance?: Attendance[];
  marks?: Array<Mark & { examSubject?: { exam?: { name: string }; subject?: Subject }; exam_subject?: { exam?: { name: string }; subject?: Subject } }>;
  routines?: Routine[];
  student_fees?: PortalFee[];
};

const pageDetails: Record<PortalPage, { title: string; description: string; endpoint: string }> = {
  classes: { title: "My Classes", description: "Your current class, section, session, and assigned subjects.", endpoint: "/student-portal/classes" },
  attendance: { title: "Attendance", description: "Your personal attendance history.", endpoint: "/student-portal/attendance" },
  results: { title: "Exams & Results", description: "Marks and grades recorded for your exams.", endpoint: "/student-portal/results" },
  routine: { title: "Routine", description: "Your class and section timetable.", endpoint: "/student-portal/routine" },
  fees: { title: "Fees", description: "Your fee dues and payment progress.", endpoint: "/student-portal/fees" },
};

export function StudentPortalPage({ page }: { page: PortalPage }) {
  const [data, setData] = useState<PortalResponse>({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const details = pageDetails[page];

  useEffect(() => {
    const timer = window.setTimeout(() => {
      void api.get<PortalResponse>(details.endpoint).then(setData).catch((cause) => {
        setError(cause instanceof ApiRequestError ? cause.message : `Unable to load ${details.title.toLowerCase()}.`);
      }).finally(() => setLoading(false));
    }, 0);
    return () => window.clearTimeout(timer);
  }, [details.endpoint, details.title]);

  if (loading) return <LoadingState label={`Loading ${details.title.toLowerCase()}...`} />;
  if (error) return <ErrorState message={error} onRetry={() => window.location.reload()} />;

  return <>
    <section className="admin-page-heading"><div><p className="kicker">Student workspace</p><h2>{details.title}</h2><p>{details.description}</p></div></section>
    {page === "classes" && <ClassesView student={data.student} subjects={data.subjects ?? []} />}
    {page === "attendance" && <AttendanceView items={data.attendance ?? []} />}
    {page === "results" && <ResultsView marks={data.marks ?? []} />}
    {page === "routine" && <RoutineView items={data.routines ?? []} />}
    {page === "fees" && <FeesView items={data.student_fees ?? []} />}
  </>;
}

function ClassesView({ student, subjects }: { student?: Student; subjects: Subject[] }) {
  if (!student?.class && !student?.section) return <EmptyState title="Class placement is not set yet" copy="Your school administrator will assign your class and section." />;
  return <>
    <section className="list-card"><div className="section-heading"><div><p className="kicker">Enrollment</p><h3>{student.class?.name ?? "Class not assigned"}</h3></div></div><div className="contact-grid">
      <div><span>Section</span><strong>{student.section?.name ?? "Not assigned"}</strong></div>
      <div><span>Session</span><strong>{student.academic_year?.name ?? student.academicYear?.name ?? "Not assigned"}</strong></div>
      <div><span>Student ID</span><strong>{student.student_id || "Not assigned"}</strong></div>
      <div><span>Roll</span><strong>{student.roll || "Not assigned"}</strong></div>
    </div></section>
    <section className="list-card"><div className="section-heading"><div><p className="kicker">Curriculum</p><h3>Assigned subjects</h3></div></div>{subjects.length ? <DataTable columns={[{ label: "Subject", key: "name" }, { label: "Code", key: "code" }]} rows={subjects.map((subject) => ({ ...subject, code: subject.code || "—" }))} /> : <EmptyState title="No subjects assigned" copy="Assigned subjects will appear here." />}</section>
  </>;
}

function AttendanceView({ items }: { items: Attendance[] }) {
  return <section className="list-card">{items.length ? <DataTable columns={[{ label: "Date", key: "date" }, { label: "Class", key: "class" }, { label: "Section", key: "section" }, { label: "Status", key: "status" }, { label: "Remarks", key: "remarks" }]} rows={items.map((item) => ({ ...item, date: item.date.slice(0, 10), class: item.class?.name ?? "—", section: item.section?.name ?? "—", status: <span className={`status-badge ${item.status === "present" ? "status-active" : "status-inactive"}`}>{item.status}</span>, remarks: item.remarks || "—" }))} /> : <EmptyState title="No attendance recorded" copy="Your attendance records will appear here." />}</section>;
}

function ResultsView({ marks }: { marks: NonNullable<PortalResponse["marks"]> }) {
  return <section className="list-card">{marks.length ? <DataTable columns={[{ label: "Exam", key: "exam" }, { label: "Subject", key: "subject" }, { label: "Marks", key: "marks" }, { label: "Grade", key: "grade" }, { label: "Grade point", key: "grade_point" }]} rows={marks.map((mark) => {
    const examSubject = mark.exam_subject ?? mark.examSubject;
    return { ...mark, exam: examSubject?.exam?.name ?? "Exam", subject: examSubject?.subject?.name ?? "—", marks: mark.marks, grade: mark.grade || "—", grade_point: mark.grade_point || "—" };
  })} /> : <EmptyState title="No results published" copy="Your exam results will appear here when they are recorded." />}</section>;
}

function RoutineView({ items }: { items: Routine[] }) {
  return <section className="list-card">{items.length ? <DataTable columns={[{ label: "Day", key: "day" }, { label: "Time", key: "time" }, { label: "Subject", key: "subject" }, { label: "Teacher", key: "teacher" }, { label: "Room", key: "room" }]} rows={items.map((item) => ({ ...item, day: item.day_of_week, time: `${item.start_time.slice(0, 5)}–${item.end_time.slice(0, 5)}`, subject: item.subject?.name ?? "—", teacher: item.teacher?.user?.name ?? "—", room: item.room_entity?.name ?? item.roomEntity?.name ?? item.room ?? "—" }))} /> : <EmptyState title="No routine available" copy="Your timetable will appear here after your class and section are assigned." />}</section>;
}

function FeesView({ items }: { items: PortalFee[] }) {
  return <section className="list-card">{items.length ? <DataTable columns={[{ label: "Fee", key: "fee" }, { label: "Due date", key: "due_date" }, { label: "Amount", key: "amount" }, { label: "Paid", key: "paid" }, { label: "Balance", key: "balance" }, { label: "Status", key: "status" }]} rows={items.map((item) => {
    const paid = item.payments?.reduce((total, payment) => total + Number(payment.amount), 0) ?? 0;
    const amount = Number(item.amount);
    return { ...item, fee: item.fee_item?.name ?? item.feeItem?.name ?? item.fee_type?.name ?? item.feeType?.name ?? "Fee", due_date: item.due_date?.slice(0, 10) ?? "—", amount: amount.toFixed(2), paid: paid.toFixed(2), balance: Math.max(0, amount - paid).toFixed(2), status: item.status };
  })} /> : <EmptyState title="No fees assigned" copy="Your fees will appear here when they are assigned by your school." />}</section>;
}
