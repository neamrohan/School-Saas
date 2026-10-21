"use client";

import { useEffect, useState, type FormEvent } from "react";
import { api, ApiRequestError } from "@/lib/api";
import { ErrorState, LoadingState, Notice } from "@/components/admin/AdminStates";
import type { AcademicYear, SchoolClass, Section } from "@/types";

export default function PublicAdmissionForm({ schoolCode }: { schoolCode: string }) {
  const [school, setSchool] = useState<{ name: string } | null>(null);
  const [years, setYears] = useState<AcademicYear[]>([]);
  const [classes, setClasses] = useState<SchoolClass[]>([]);
  const [sections, setSections] = useState<Section[]>([]);
  const [form, setForm] = useState({ name: "", email: "", phone: "", guardian_name: "", guardian_phone: "", date_of_birth: "", gender: "", address: "", academic_year_id: "", class_id: "", section_id: "" });
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  useEffect(() => {
    const timer = window.setTimeout(() => {
      Promise.all([
        api.get<{ settings: { website_name: string } }>(`/public/schools/${encodeURIComponent(schoolCode)}/website`),
        api.get<{ academic_years: AcademicYear[] }>(`/public/schools/${encodeURIComponent(schoolCode)}/admission-options/academic-years`),
      ]).then(([schoolResult, yearResult]) => {
        setSchool({ name: schoolResult.settings.website_name });
        setYears(yearResult.academic_years);
      }).catch((cause) => {
        setError(cause instanceof ApiRequestError ? cause.message : "Unable to load the admission form.");
      }).finally(() => setLoading(false));
    }, 0);
    return () => window.clearTimeout(timer);
  }, [schoolCode]);

  useEffect(() => {
    if (!form.academic_year_id) return;
    api.get<{ classes: SchoolClass[] }>(`/public/schools/${encodeURIComponent(schoolCode)}/admission-options/classes?academic_year_id=${form.academic_year_id}`)
      .then((result) => setClasses(result.classes))
      .catch((cause) => setError(cause instanceof ApiRequestError ? cause.message : "Unable to load classes."));
  }, [form.academic_year_id, schoolCode]);

  useEffect(() => {
    if (!form.class_id) {
      return;
    }
    let active = true;
    api.get<{ sections: Section[] }>(`/public/schools/${encodeURIComponent(schoolCode)}/admission-options/sections?class_id=${form.class_id}`)
      .then((result) => { if (active) setSections(result.sections); })
      .catch((cause) => { if (active) setError(cause instanceof ApiRequestError ? cause.message : "Unable to load sections."); });
    return () => { active = false; };
  }, [form.class_id, schoolCode]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setError("");
    setNotice("");
    try {
      const result = await api.post<{ message: string }>(`/public/schools/${encodeURIComponent(schoolCode)}/admissions`, {
        ...form,
        academic_year_id: Number(form.academic_year_id),
        class_id: Number(form.class_id),
        section_id: form.section_id ? Number(form.section_id) : null,
      });
      setNotice(result.message);
      setForm({ name: "", email: "", phone: "", guardian_name: "", guardian_phone: "", date_of_birth: "", gender: "", address: "", academic_year_id: "", class_id: "", section_id: "" });
    } catch (cause) {
      setError(cause instanceof ApiRequestError ? cause.message : "Unable to submit the application.");
    } finally {
      setBusy(false);
    }
  }

  if (loading) return <LoadingState label="Loading admission form..." />;
  if (error && !school) return <ErrorState message={error} onRetry={() => window.location.reload()} />;
  return <main className="public-admission-page"><section className="list-card public-admission-card">
    <div className="admin-page-heading"><div><p className="kicker">Online admission</p><h2>{school?.name ?? "School"} application</h2><p>Submit an application for review. Submission does not create an enrolled student until the school approves it.</p></div></div>
    {notice && <Notice message={notice} />}{error && <p className="field-error" role="alert">{error}</p>}
    <form className="school-form report-filter-form" onSubmit={submit}><div className="form-grid">
      <label>Applicant name<input value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} required maxLength={255} /></label>
      <label>Email<input type="email" value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} required maxLength={255} /></label>
      <label>Phone<input value={form.phone} onChange={(event) => setForm({ ...form, phone: event.target.value })} maxLength={50} /></label>
      <label>Date of birth<input type="date" value={form.date_of_birth} onChange={(event) => setForm({ ...form, date_of_birth: event.target.value })} /></label>
      <label>Gender<input value={form.gender} onChange={(event) => setForm({ ...form, gender: event.target.value })} maxLength={50} /></label>
      <label>Academic session<select value={form.academic_year_id} onChange={(event) => setForm({ ...form, academic_year_id: event.target.value, class_id: "", section_id: "" })} required><option value="">Select session</option>{years.map((year) => <option key={year.id} value={year.id}>{year.name}</option>)}</select></label>
      <label>Class<select value={form.class_id} onChange={(event) => setForm({ ...form, class_id: event.target.value, section_id: "" })} required disabled={!form.academic_year_id}><option value="">Select class</option>{classes.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
      <label>Section<select value={form.section_id} onChange={(event) => setForm({ ...form, section_id: event.target.value })} disabled={!form.class_id}><option value="">No preference</option>{(form.class_id ? sections : []).map((section) => <option key={section.id} value={section.id}>{section.name}</option>)}</select></label>
      <label>Guardian name<input value={form.guardian_name} onChange={(event) => setForm({ ...form, guardian_name: event.target.value })} required maxLength={255} /></label>
      <label>Guardian phone<input value={form.guardian_phone} onChange={(event) => setForm({ ...form, guardian_phone: event.target.value })} required maxLength={50} /></label>
      <label className="wide-field">Address<textarea rows={3} value={form.address} onChange={(event) => setForm({ ...form, address: event.target.value })} maxLength={2000} /></label>
    </div><button className="primary-button" disabled={busy}>{busy ? "Submitting..." : "Submit application"}</button></form>
  </section></main>;
}
