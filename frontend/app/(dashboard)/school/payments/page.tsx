"use client";

import { useEffect, useState } from "react";
import { useSearchParams } from "next/navigation";
import { api, ApiRequestError } from "@/lib/api";
import { EmptyState, ErrorState, LoadingState, Notice } from "@/components/admin/AdminStates";
import type { Student } from "@/types";

type Fee = { id: number; amount: string; status: string; student?: Student; feeType?: { name: string }; feeItem?: { name: string }; payments?: Array<{ amount: string }> };
type Payment = { id: number; amount: string; payment_date: string; payment_method: string; student?: Student; studentFee?: Fee };
type PaymentView = "quick-payment" | "customer-payment" | "payment-list" | "due-list";

const paymentViews: PaymentView[] = ["quick-payment", "customer-payment", "payment-list", "due-list"];

export default function PaymentsPage() {
  const searchParams = useSearchParams();
  const requestedView = searchParams.get("tab");
  const view: PaymentView = paymentViews.includes(requestedView as PaymentView) ? requestedView as PaymentView : "quick-payment";
  const [fees, setFees] = useState<Fee[]>([]);
  const [payments, setPayments] = useState<Payment[]>([]);
  const [studentId, setStudentId] = useState("");
  const [feeId, setFeeId] = useState("");
  const [amount, setAmount] = useState("");
  const [method, setMethod] = useState("cash");
  const [students, setStudents] = useState<Student[]>([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  useEffect(() => {
    Promise.all([
      api.get<{ student_fees: Fee[] }>("/student-fees"),
      api.get<{ payments: Payment[] }>("/payments"),
      api.get<{ students: Student[] }>("/students"),
    ]).then(([feeResult, paymentResult, studentResult]) => {
      setFees(feeResult.student_fees);
      setPayments(paymentResult.payments);
      setStudents(studentResult.students);
    }).catch((cause) => {
      setError(cause instanceof ApiRequestError ? cause.message : "Unable to load payments.");
    }).finally(() => setLoading(false));
  }, []);

  const selectedFees = fees.filter((fee) => String(fee.student?.id) === studentId && fee.status !== "paid");

  async function pay(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setError("");
    try {
      const fee = fees.find((item) => String(item.id) === feeId);
      const result = await api.post<{ payment: Payment }>("/payments", {
        student_fee_id: Number(feeId),
        student_id: Number(studentId),
        amount,
        payment_date: new Date().toISOString().slice(0, 10),
        payment_method: method,
      });
      setPayments((current) => [result.payment, ...current]);
      setNotice("Payment recorded successfully.");
      setAmount("");
      setFees((current) => current.map((item) => item.id === fee?.id ? { ...item, status: "partial" } : item));
    } catch (cause) {
      setError(cause instanceof ApiRequestError ? cause.message : "Unable to record payment.");
    } finally {
      setBusy(false);
    }
  }

  if (loading) return <LoadingState label="Loading payments..." />;
  if (error && !students.length) return <ErrorState message={error} onRetry={() => window.location.reload()} />;

  const paymentEntry = view === "quick-payment" || view === "customer-payment";
  const heading = view === "quick-payment" ? "Quick Payment" : view === "customer-payment" ? "Customer Payment" : view === "payment-list" ? "Payment List" : "Due List";

  return <>
    <section className="admin-page-heading">
      <div><p className="kicker">Finance workspace</p><h2>{heading}</h2><p>Manage student payments and outstanding dues.</p></div>
    </section>
    {notice && <Notice message={notice} />}
    {error && <p className="field-error">{error}</p>}
    {paymentEntry && <section className="list-card">
      <h3>{heading}</h3>
      <form className="school-form" onSubmit={pay}>
        <div className="form-grid">
          <label>Student<select value={studentId} onChange={(event) => { setStudentId(event.target.value); setFeeId(""); }} required><option value="">Select student</option>{students.map((student) => <option key={student.id} value={student.id}>{student.student_id ?? student.id} · {student.user?.name}</option>)}</select></label>
          <label>Due<select value={feeId} onChange={(event) => setFeeId(event.target.value)} required><option value="">Select due</option>{selectedFees.map((fee) => <option key={fee.id} value={fee.id}>{fee.feeItem?.name ?? fee.feeType?.name} · {fee.amount}</option>)}</select></label>
          <label>Amount<input value={amount} onChange={(event) => setAmount(event.target.value)} inputMode="decimal" required /></label>
          <label>Payment method<select value={method} onChange={(event) => setMethod(event.target.value)}><option value="cash">Cash</option><option value="bank">Bank</option><option value="mobile_banking">Mobile banking</option><option value="other">Other</option></select></label>
        </div>
        <button className="primary-button" disabled={busy}>{busy ? "Saving..." : "Record payment"}</button>
      </form>
    </section>}
    {view === "payment-list" && <section className="list-card">
      <h3>Payment List</h3>
      {payments.length ? payments.map((payment) => <div className="list-toolbar" key={payment.id}><span>{payment.student?.user?.name} · {payment.studentFee?.feeItem?.name ?? payment.studentFee?.feeType?.name}</span><span>{payment.amount} · {payment.payment_method} · {payment.payment_date}</span></div>) : <EmptyState title="No payments" copy="Payments will appear here." />}
    </section>}
    {view === "due-list" && <section className="list-card">
      <h3>Due List</h3>
      {fees.filter((fee) => fee.status !== "paid").length ? fees.filter((fee) => fee.status !== "paid").map((fee) => {
        const paid = fee.payments?.reduce((sum, payment) => sum + Number(payment.amount), 0) ?? 0;
        return <div className="list-toolbar" key={fee.id}><span>{fee.student?.user?.name} · {fee.feeItem?.name ?? fee.feeType?.name}</span><span>Total {fee.amount} · Paid {paid.toFixed(2)} · Due {(Number(fee.amount) - paid).toFixed(2)}</span></div>;
      }) : <EmptyState title="No dues" copy="Student dues will appear here." />}
    </section>}
  </>;
}