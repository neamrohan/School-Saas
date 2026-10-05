"use client";

import { useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { api, ApiRequestError } from "@/lib/api";
import { EmptyState, ErrorState, LoadingState, Notice } from "@/components/admin/AdminStates";
import type { AcademicYear, SchoolClass, Student } from "@/types";

type Category = { id: number; name: string; description?: string | null };
type Item = { id: number; name: string; category?: Category; category_id: number };
type Pricing = { id: number; amount: string; item?: Item; academicYear?: AcademicYear; class?: SchoolClass | null };

export default function FeesPaymentsPage() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const tabs = ["categories", "items", "pricing", "monthly", "generate"];
  const requestedTab = searchParams.get("tab");
  const tab = requestedTab && tabs.includes(requestedTab) ? requestedTab : "categories";
  const [categories, setCategories] = useState<Category[]>([]);
  const [items, setItems] = useState<Item[]>([]);
  const [years, setYears] = useState<AcademicYear[]>([]);
  const [classes, setClasses] = useState<SchoolClass[]>([]);
  const [students, setStudents] = useState<Student[]>([]);
  const [pricings, setPricings] = useState<Pricing[]>([]);
  const [form, setForm] = useState({ name: "", category_id: "", fee_item_id: "", academic_year_id: "", class_id: "", amount: "", month: "", student_id: "" });
  const [loading, setLoading] = useState(true); const [busy, setBusy] = useState(false); const [error, setError] = useState(""); const [notice, setNotice] = useState(""); const [editingId, setEditingId] = useState<number | null>(null);

  async function load() {
    try {
      const [categoryResult, itemResult, yearResult, classResult, studentResult, pricingResult] = await Promise.all([
        api.get<{ categories: Category[] }>("/fee-categories"), api.get<{ items: Item[] }>("/fee-items"),
        api.get<{ academic_years: AcademicYear[] }>("/academic-years"), api.get<{ classes: SchoolClass[] }>("/classes"),
        api.get<{ students: Student[] }>("/students"), api.get<{ pricings: Pricing[] }>("/fee-pricings"),
      ]);
      setCategories(categoryResult.categories); setItems(itemResult.items); setYears(yearResult.academic_years); setClasses(classResult.classes); setStudents(studentResult.students); setPricings(pricingResult.pricings);
    } catch (cause) { setError(cause instanceof ApiRequestError ? cause.message : "Unable to load fees."); } finally { setLoading(false); }
  }
  useEffect(() => { const timer = window.setTimeout(() => void load(), 0); return () => window.clearTimeout(timer); }, []);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true); setError(""); setNotice("");
    try {
      if (tab === "categories") { const r = editingId ? await api.put<{ category: Category }>(`/fee-categories/${editingId}`, { name: form.name }) : await api.post<{ category: Category }>("/fee-categories", { name: form.name }); setCategories((c) => editingId ? c.map((item) => item.id === editingId ? r.category : item) : [r.category, ...c]); }
      if (tab === "items") { const r = editingId ? await api.put<{ item: Item }>(`/fee-items/${editingId}`, { name: form.name, category_id: Number(form.category_id) }) : await api.post<{ item: Item }>("/fee-items", { name: form.name, category_id: Number(form.category_id) }); setItems((c) => editingId ? c.map((item) => item.id === editingId ? r.item : item) : [r.item, ...c]); }
      if (tab === "pricing") { const r = await api.post<{ pricing: Pricing }>("/fee-pricings", { fee_item_id: Number(form.fee_item_id), academic_year_id: Number(form.academic_year_id), class_id: form.class_id ? Number(form.class_id) : null, amount: form.amount }); setPricings((c) => [r.pricing, ...c]); }
      if (tab === "monthly") { await api.post("/monthly-fee-setups", { fee_item_id: Number(form.fee_item_id), academic_year_id: Number(form.academic_year_id), class_id: form.class_id ? Number(form.class_id) : null, month: Number(form.month), amount: form.amount }); }
      if (tab === "generate") { const r = await api.post<{ generated: number; already_existed: number }>("/student-fees/generate", { student_id: Number(form.student_id), fee_item_id: Number(form.fee_item_id), academic_year_id: Number(form.academic_year_id), class_id: Number(form.class_id), month: Number(form.month) }); setNotice(`Generated ${r.generated}; already existed ${r.already_existed}.`); }
      if (tab !== "generate") setNotice("Saved successfully."); setEditingId(null); setForm({ name: "", category_id: "", fee_item_id: "", academic_year_id: "", class_id: "", amount: "", month: "", student_id: "" });
    } catch (cause) { setError(cause instanceof ApiRequestError ? cause.message : "Unable to save fee configuration."); } finally { setBusy(false); }
  }
  if (loading) return <LoadingState label="Loading fees..." />; if (error && !categories.length && !items.length) return <ErrorState message={error} onRetry={() => void load()} />;
  return <><section className="admin-page-heading"><div><p className="kicker">Finance workspace</p><h2>Fees & Payments</h2><p>Configure fee items, pricing, monthly setup, and student dues.</p></div></section>{notice && <Notice message={notice} />}{error && <p className="field-error">{error}</p>}<section className="list-card"><div className="list-toolbar">{tabs.map((value) => <button key={value} className={tab === value ? "primary-button compact-button" : "secondary-button"} onClick={() => { setEditingId(null); router.replace(`/school/fees?tab=${value}`); }}>{value.replace("-", " ")}</button>)}</div><form className="school-form" onSubmit={submit}><div className="form-grid">{(tab === "categories" || tab === "items") && <label>Name<input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required /></label>}{tab === "items" && <label>Category<select value={form.category_id} onChange={(e) => setForm({ ...form, category_id: e.target.value })} required><option value="">Select category</option>{categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></label>}{["pricing", "monthly", "generate"].includes(tab) && <><label>Item<select value={form.fee_item_id} onChange={(e) => setForm({ ...form, fee_item_id: e.target.value })} required><option value="">Select item</option>{items.map((i) => <option key={i.id} value={i.id}>{i.name}</option>)}</select></label><label>Academic Year<select value={form.academic_year_id} onChange={(e) => setForm({ ...form, academic_year_id: e.target.value })} required><option value="">Select year</option>{years.map((y) => <option key={y.id} value={y.id}>{y.name}</option>)}</select></label><label>Class<select value={form.class_id} onChange={(e) => setForm({ ...form, class_id: e.target.value })} required><option value="">Select class</option>{classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></label></>}{["pricing", "monthly"].includes(tab) && <label>Amount<input value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} inputMode="decimal" required /></label>}{["monthly", "generate"].includes(tab) && <label>Month<select value={form.month} onChange={(e) => setForm({ ...form, month: e.target.value })} required><option value="">Select month</option>{Array.from({ length: 12 }, (_, i) => <option key={i + 1} value={i + 1}>{new Date(2026, i).toLocaleString("en", { month: "long" })}</option>)}</select></label>}{tab === "generate" && <label>Student (optional for all students)<select value={form.student_id} onChange={(e) => setForm({ ...form, student_id: e.target.value })}><option value="">All students in class</option>{students.filter((s) => !form.class_id || s.class_id === Number(form.class_id)).map((s) => <option key={s.id} value={s.id}>{s.student_id ?? s.id} · {s.user?.name}</option>)}</select></label>}</div><button className="primary-button" disabled={busy}>{busy ? "Saving..." : tab === "generate" ? "Generate due" : editingId ? "Update" : "Save"}</button></form></section><section className="list-card"><h3>{tab === "categories" ? "Categories" : tab === "items" ? "Items" : "Pricing"}</h3>{tab === "categories" ? (categories.length ? categories.map((c) => <div className="list-toolbar" key={c.id}><span>{c.name}</span><button className="secondary-button compact-button" onClick={() => { setEditingId(c.id); setForm({ ...form, name: c.name }); }}>Edit</button><button className="secondary-button compact-button" onClick={() => { if (window.confirm("Delete this category?")) void api.delete(`/fee-categories/${c.id}`).then(() => setCategories((current) => current.filter((item) => item.id !== c.id))); }}>Delete</button></div>) : <EmptyState title="No categories" copy="Create a fee category." />) : tab === "items" ? (items.length ? items.map((i) => <div className="list-toolbar" key={i.id}><span>{i.name}</span><span>{i.category?.name}</span><button className="secondary-button compact-button" onClick={() => { setEditingId(i.id); setForm({ ...form, name: i.name, category_id: String(i.category_id) }); }}>Edit</button><button className="secondary-button compact-button" onClick={() => { if (window.confirm("Delete this item?")) void api.delete(`/fee-items/${i.id}`).then(() => setItems((current) => current.filter((item) => item.id !== i.id))); }}>Delete</button></div>) : <EmptyState title="No items" copy="Create a fee item." />) : (pricings.length ? pricings.map((p) => <div className="list-toolbar" key={p.id}><span>{p.item?.name}</span><span>{p.amount}</span></div>) : <EmptyState title="No pricing" copy="Create item pricing." />)}</section></>;
}
