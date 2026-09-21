import { FormEvent, useState } from "react";
import type { Parent } from "@/types";

export interface ParentFormValues { name: string; email: string; password: string; }

export function ParentForm({ parent, busy, errors, onSubmit, onCancel }: { parent?: Parent | null; busy: boolean; errors?: Record<string, string[]>; onSubmit: (values: ParentFormValues) => void; onCancel: () => void }) {
  const [values, setValues] = useState<ParentFormValues>({ name: parent?.name ?? "", email: parent?.email ?? "", password: "" });
  const set = (key: keyof ParentFormValues, value: string) => setValues((current) => ({ ...current, [key]: value }));
  function submit(event: FormEvent<HTMLFormElement>) { event.preventDefault(); if (!values.name.trim() || !values.email.trim() || (!parent && !values.password)) return; onSubmit(values); }
  return <form className="school-form" onSubmit={submit}><div className="form-grid"><label>Parent name<input value={values.name} onChange={(event) => set("name", event.target.value)} required />{errors?.name && <small className="field-error">{errors.name[0]}</small>}</label><label>Email<input type="email" value={values.email} onChange={(event) => set("email", event.target.value)} required />{errors?.email && <small className="field-error">{errors.email[0]}</small>}</label><label className="wide-field">Password<input type="password" value={values.password} onChange={(event) => set("password", event.target.value)} placeholder={parent ? "Leave blank to keep current password" : "At least 8 characters"} required={!parent} />{errors?.password && <small className="field-error">{errors.password[0]}</small>}</label></div>{errors?._form && <div className="form-error">{errors._form[0]}</div>}<div className="form-actions"><button type="button" className="secondary-button" onClick={onCancel}>Cancel</button><button type="submit" className="primary-button" disabled={busy}>{busy ? "Saving..." : parent ? "Save changes" : "Add parent"}</button></div></form>;
}
