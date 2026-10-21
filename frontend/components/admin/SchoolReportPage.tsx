"use client";

import { useCallback, useEffect, useState, type FormEvent } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { api, ApiRequestError } from "@/lib/api";
import { EmptyState, ErrorState, LoadingState } from "@/components/admin/AdminStates";

type ReportSlug =
  | "admission-session-counting"
  | "admission-collection"
  | "student-collection"
  | "student-monthly-collection"
  | "student-attendance"
  | "sms"
  | "expenses"
  | "income"
  | "cash-book"
  | "bank-book"
  | "account-clearance";

type ReportDefinition = {
  title: string;
  description: string;
  endpoint?: string;
  filters: Array<"dates" | "class" | "session" | "student" | "status">;
  limitation?: string;
  unavailableColumns?: string[];
};

const reports: Record<ReportSlug, ReportDefinition> = {
  "admission-session-counting": {
    title: "Admission, Session Counting Reports",
    description: "Review student admissions and counts by academic session.",
    endpoint: "/reports/admissions",
    filters: ["dates", "class", "session"],
  },
  "admission-collection": {
    title: "Admission Collection Reports",
    description: "Review recorded payments attached to admission-labelled fees.",
    endpoint: "/reports/admission-collections",
    filters: ["dates", "class", "session", "student", "status"],
    limitation: "Admission payments are identified by fee type, item, or category names containing “admission”. Check your fee naming if this report is empty.",
  },
  "student-collection": {
    title: "Student Collection Reports",
    description: "Review student payment transactions recorded by the school.",
    endpoint: "/reports/collections",
    filters: ["dates", "class", "session", "student", "status"],
  },
  "student-monthly-collection": {
    title: "Student Monthly Collection Reports",
    description: "Compare recorded student payment receipts by payment month.",
    endpoint: "/reports/monthly-collections",
    filters: ["dates", "class", "session", "student", "status"],
  },
  "student-attendance": {
    title: "Student Attendance Reports",
    description: "Review attendance records by date, class, session, or student.",
    endpoint: "/reports/attendance",
    filters: ["dates", "class", "session", "student"],
  },
  sms: {
    title: "SMS Reports",
    description: "SMS history and delivery statuses for this school.",
    filters: ["dates"],
    limitation: "This project does not currently have an SMS log table, delivery-status records, or an SMS reporting API. No sample or placeholder messages are shown.",
    unavailableColumns: ["Sent at", "Recipient", "Message", "Delivery status"],
  },
  expenses: {
    title: "Expense Reports",
    description: "School expenses recorded during a selected date range.",
    filters: ["dates"],
    limitation: "There is no expense ledger or expense API in the current backend. Student fee payments are not treated as expenses.",
    unavailableColumns: ["Date", "Category", "Description", "Amount"],
  },
  income: {
    title: "Income Reports",
    description: "School income recorded during a selected date range.",
    filters: ["dates"],
    limitation: "There is no general income ledger or income API in the current backend. Student fee receipts are available separately in Student Collection Reports.",
    unavailableColumns: ["Date", "Income source", "Description", "Amount"],
  },
  "cash-book": {
    title: "Cash Book Reports",
    description: "Cash-method student payment receipts recorded for the school.",
    endpoint: "/reports/cash-book",
    filters: ["dates", "class", "session", "student"],
    limitation: "This view includes recorded cash student receipts only. Opening balances, non-student income, and cash expenses are not stored in the current project.",
  },
  "bank-book": {
    title: "Bank Book Reports",
    description: "Bank and mobile-banking student payment receipts recorded for the school.",
    endpoint: "/reports/bank-book",
    filters: ["dates", "class", "session", "student"],
    limitation: "This view includes recorded bank and mobile-banking student receipts only. Opening balances, transfers, non-student income, and expenses are not stored in the current project.",
  },
  "account-clearance": {
    title: "Account Clearance Reports",
    description: "Review student fee balances and identify accounts with remaining dues.",
    endpoint: "/reports/fees",
    filters: ["class", "session", "student", "status"],
  },
};

type ClassOption = { id: number; name: string };
type SessionOption = { id: number; name: string };
type StudentOption = { id: number; student_id?: string | null; user?: { name?: string } };
type StudentRecord = StudentOption & {
  admission_date?: string | null;
  class?: { name?: string } | null;
  academic_year?: { name?: string } | null;
};
type PaymentRecord = {
  id: number;
  amount: string;
  payment_date: string;
  payment_method: string;
  student?: StudentRecord | null;
  student_fee?: {
    fee_type?: { name?: string } | null;
    fee_item?: { name?: string } | null;
  } | null;
};
type ReportRow = { id: number; cells: string[] };
type ReportStat = { label: string; value: string | number };
type Filters = { date_from: string; date_to: string; class_id: string; academic_year_id: string; student_id: string; status: string };

const reportOrder: Array<{ slug: ReportSlug; title: string }> = Object.entries(reports).map(([slug, definition]) => ({
  slug: slug as ReportSlug,
  title: definition.title,
}));

const initialFilters: Filters = { date_from: "", date_to: "", class_id: "", academic_year_id: "", student_id: "", status: "" };

function formatMoney(value: string | number | undefined): string {
  const amount = Number(value ?? 0);
  return Number.isFinite(amount) ? amount.toFixed(2) : "0.00";
}

function studentLabel(student?: StudentRecord | null): string {
  return student?.user?.name || student?.student_id || (student ? `Student ${student.id}` : "—");
}

function paymentRows(payments: PaymentRecord[]): ReportRow[] {
  return payments.map((payment) => ({
    id: payment.id,
    cells: [
      payment.payment_date,
      studentLabel(payment.student),
      payment.student?.class?.name ?? "—",
      payment.student_fee?.fee_item?.name ?? payment.student_fee?.fee_type?.name ?? "—",
      payment.payment_method.replaceAll("_", " "),
      formatMoney(payment.amount),
    ],
  }));
}

export function SchoolReportIndex() {
  return (
    <>
      <section className="admin-page-heading">
        <div><p className="kicker">School reports</p><h2>Reports</h2><p>Choose a report to explore school records and activity.</p></div>
      </section>
      <section className="report-index-grid">
        {reportOrder.map(({ slug, title }) => (
          <Link className="report-index-link" href={`/school/reports/${slug}`} key={slug}>
            <span>{title}</span><span aria-hidden="true">→</span>
          </Link>
        ))}
      </section>
    </>
  );
}

export default function SchoolReportPage() {
  const { report: requestedReport } = useParams<{ report: string }>();
  const router = useRouter();
  const slug = requestedReport as ReportSlug;
  const definition = reports[slug];
  const [filters, setFilters] = useState<Filters>(initialFilters);
  const [draftFilters, setDraftFilters] = useState<Filters>(initialFilters);
  const [classes, setClasses] = useState<ClassOption[]>([]);
  const [sessions, setSessions] = useState<SessionOption[]>([]);
  const [students, setStudents] = useState<StudentOption[]>([]);
  const [rows, setRows] = useState<ReportRow[]>([]);
  const [sessionCounts, setSessionCounts] = useState<Array<{ session: string; count: number }>>([]);
  const [columns, setColumns] = useState<string[]>([]);
  const [stats, setStats] = useState<ReportStat[]>([]);
  const [loading, setLoading] = useState(Boolean(definition?.endpoint));
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    if (!definition?.endpoint) {
      setLoading(false);
      return;
    }
    setLoading(true);
    setError("");
    try {
      const [classResult, sessionResult, studentResult] = await Promise.all([
        api.get<{ classes: ClassOption[] }>("/classes"),
        api.get<{ academic_years: SessionOption[] }>("/academic-years"),
        api.get<{ students: StudentOption[] }>("/students"),
      ]);
      setClasses(classResult.classes);
      setSessions(sessionResult.academic_years);
      setStudents(studentResult.students);

      const query = new URLSearchParams();
      Object.entries(filters).forEach(([key, value]) => {
        if (value) query.set(key, value);
      });
      const suffix = query.size ? `?${query.toString()}` : "";

      if (slug === "admission-session-counting") {
        const result = await api.get<{ admissions: StudentRecord[]; total_admissions: number; session_counts: Array<{ session: string; count: number }> }>(`${definition.endpoint}${suffix}`);
        setStats([{ label: "Admissions", value: result.total_admissions }, { label: "Sessions represented", value: result.session_counts.length }]);
        setSessionCounts(result.session_counts);
        setColumns(["Student", "Student ID", "Admission date", "Class", "Academic session"]);
        setRows(result.admissions.map((student) => ({
          id: student.id,
          cells: [studentLabel(student), student.student_id ?? "—", student.admission_date ?? "—", student.class?.name ?? "—", student.academic_year?.name ?? "No session"],
        })));
      } else if (slug === "admission-collection" || slug === "student-collection" || slug === "cash-book" || slug === "bank-book") {
        setSessionCounts([]);
        const result = await api.get<{ payments: PaymentRecord[]; payment_count: number; total_collected: string }>(`${definition.endpoint}${suffix}`);
        setStats([{ label: "Transactions", value: result.payment_count }, { label: "Total collected", value: formatMoney(result.total_collected) }]);
        setColumns(["Payment date", "Student", "Class", "Fee", "Method", "Amount"]);
        setRows(paymentRows(result.payments));
      } else if (slug === "student-monthly-collection") {
        setSessionCounts([]);
        const result = await api.get<{ months: Array<{ month: string; payment_count: number; total_collected: string }>; payment_count: number; total_collected: string }>(`${definition.endpoint}${suffix}`);
        setStats([{ label: "Transactions", value: result.payment_count }, { label: "Total collected", value: formatMoney(result.total_collected) }]);
        setColumns(["Payment month", "Transactions", "Total collected"]);
        setRows(result.months.map((month, index) => ({ id: index, cells: [month.month, String(month.payment_count), formatMoney(month.total_collected)] })));
      } else if (slug === "student-attendance") {
        setSessionCounts([]);
        const result = await api.get<{ attendances: Array<{ id: number; date: string; status: string; student?: StudentRecord; class?: { name?: string } }>; summary: Record<string, number> }>(`${definition.endpoint}${suffix}`);
        setStats(Object.entries(result.summary).map(([status, count]) => ({ label: status, value: count })));
        setColumns(["Date", "Student", "Class", "Status"]);
        setRows(result.attendances.map((record) => ({ id: record.id, cells: [record.date, studentLabel(record.student), record.class?.name ?? "—", record.status] })));
      } else if (slug === "account-clearance") {
        setSessionCounts([]);
        const result = await api.get<{ fees: Array<{ id: number; amount: string; status: string; student?: StudentRecord; payments?: Array<{ amount: string }> }>; total_amount: string; total_paid: string; total_due: string }>(`${definition.endpoint}${suffix}`);
        setStats([{ label: "Fees assessed", value: formatMoney(result.total_amount) }, { label: "Paid", value: formatMoney(result.total_paid) }, { label: "Outstanding", value: formatMoney(result.total_due) }]);
        setColumns(["Student", "Class", "Fee amount", "Paid", "Balance", "Status"]);
        setRows(result.fees.map((fee) => {
          const paid = fee.payments?.reduce((sum, payment) => sum + Number(payment.amount), 0) ?? 0;
          const balance = Math.max(0, Number(fee.amount) - paid);
          return { id: fee.id, cells: [studentLabel(fee.student), fee.student?.class?.name ?? "—", formatMoney(fee.amount), formatMoney(paid), formatMoney(balance), balance === 0 ? "Cleared" : "Due"] };
        }));
      }
    } catch (cause) {
      setError(cause instanceof ApiRequestError ? cause.message : "Unable to load this report.");
    } finally {
      setLoading(false);
    }
  }, [definition, filters, slug]);

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  if (!definition) return <ErrorState message="This report page does not exist." onRetry={() => router.push("/school/reports")} />;
  if (loading) return <LoadingState label={`Loading ${definition.title.toLowerCase()}...`} />;
  if (error) return <ErrorState message={error} onRetry={() => void load()} />;

  function submitFilters(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setFilters(draftFilters);
  }

  function updateFilter(key: keyof Filters, value: string) {
    setDraftFilters((current) => ({ ...current, [key]: value }));
  }

  return (
    <>
      <section className="admin-page-heading">
        <div><p className="kicker">School reports</p><h2>{definition.title}</h2><p>{definition.description}</p></div>
      </section>
      {definition.limitation && <div className={`report-limitation ${definition.endpoint ? "" : "report-unavailable"}`} role="note">{definition.limitation}</div>}
      {!definition.endpoint && <section className="report-summary-grid" aria-label="Report summary"><article className="report-summary-card"><span>Data source</span><strong>Not configured</strong></article></section>}
      {definition.filters.length > 0 && <section className="list-card report-filter-card">
        <h3>Report filters</h3>
        <form className="school-form report-filter-form" onSubmit={definition.endpoint ? submitFilters : (event) => event.preventDefault()}>
          <div className="form-grid">
            {definition.filters.includes("dates") && <>
              <label>Start date<input type="date" disabled={!definition.endpoint} value={draftFilters.date_from} onChange={(event) => updateFilter("date_from", event.target.value)} /></label>
              <label>End date<input type="date" disabled={!definition.endpoint} value={draftFilters.date_to} onChange={(event) => updateFilter("date_to", event.target.value)} /></label>
            </>}
            {definition.endpoint && definition.filters.includes("class") && <label>Class<select value={draftFilters.class_id} onChange={(event) => updateFilter("class_id", event.target.value)}><option value="">All classes</option>{classes.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>}
            {definition.endpoint && definition.filters.includes("session") && <label>Academic session<select value={draftFilters.academic_year_id} onChange={(event) => updateFilter("academic_year_id", event.target.value)}><option value="">All sessions</option>{sessions.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>}
            {definition.endpoint && definition.filters.includes("student") && <label>Student<select value={draftFilters.student_id} onChange={(event) => updateFilter("student_id", event.target.value)}><option value="">All students</option>{students.map((item) => <option key={item.id} value={item.id}>{item.student_id ?? item.id} · {item.user?.name ?? "Student"}</option>)}</select></label>}
            {definition.endpoint && definition.filters.includes("status") && <label>Payment status<select value={draftFilters.status} onChange={(event) => updateFilter("status", event.target.value)}><option value="">All statuses</option><option value="paid">Paid</option><option value="partial">Partial</option><option value="unpaid">Unpaid</option></select></label>}
          </div>
          {definition.endpoint
            ? <div className="report-filter-actions"><button className="primary-button" type="submit">Apply filters</button><button className="secondary-button" type="button" onClick={() => { setDraftFilters(initialFilters); setFilters(initialFilters); }}>Clear filters</button></div>
            : <p className="report-filter-hint">Filters will be enabled when this report has a school-scoped data source.</p>}
        </form>
      </section>}
      {definition.endpoint && (
        <>
          {stats.length > 0 && <section className="report-summary-grid" aria-label="Report summary">{stats.map((stat) => <article className="report-summary-card" key={stat.label}><span>{stat.label}</span><strong>{stat.value}</strong></article>)}</section>}
          {sessionCounts.length > 0 && <section className="list-card report-table-card"><h3>Admissions by academic session</h3><div className="data-table-wrap"><table className="data-table"><thead><tr><th scope="col">Academic session</th><th scope="col">Admissions</th></tr></thead><tbody>{sessionCounts.map((item) => <tr key={item.session}><td>{item.session}</td><td>{item.count}</td></tr>)}</tbody></table></div></section>}
          <section className="list-card report-table-card">
            <h3>{definition.title}</h3>
            {rows.length ? <div className="data-table-wrap"><table className="data-table"><thead><tr>{columns.map((column) => <th key={column} scope="col">{column}</th>)}</tr></thead><tbody>{rows.map((row) => <tr key={row.id}>{row.cells.map((cell, index) => <td key={`${row.id}-${index}`}>{cell}</td>)}</tr>)}</tbody></table></div> : <EmptyState title="No report records" copy="No records match the selected filters." />}
          </section>
        </>
      )}
      {!definition.endpoint && definition.unavailableColumns && <section className="list-card report-table-card">
        <h3>{definition.title}</h3>
        <div className="data-table-wrap"><table className="data-table"><thead><tr>{definition.unavailableColumns.map((column) => <th key={column} scope="col">{column}</th>)}</tr></thead><tbody><tr><td colSpan={definition.unavailableColumns.length}><EmptyState title="Report data not available" copy="Connect a school-scoped backend data source to populate this report." /></td></tr></tbody></table></div>
      </section>}
    </>
  );
}
