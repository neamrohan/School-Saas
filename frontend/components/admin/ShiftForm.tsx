import { FormEvent, useState } from "react";
import type { Shift } from "@/types";

export interface ShiftFormValues {
  name: string;
  code: string;
  start_time: string;
  end_time: string;
  is_active: boolean;
}

export function ShiftForm({ shift, busy, errors, onSubmit, onCancel }: { shift?: Shift | null; busy: boolean; errors?: Record<string, string[]>; onSubmit: (values: ShiftFormValues) => void; onCancel: () => void }) {
  const [values, setValues] = useState<ShiftFormValues>({ name: shift?.name ?? "", code: shift?.code ?? "", start_time: shift?.start_time?.slice(0, 5) ?? "", end_time: shift?.end_time?.slice(0, 5) ?? "", is_active: shift?.is_active ?? true });
  const set = (key: keyof ShiftFormValues, value: string | boolean) => setValues((current) => ({ ...current, [key]: value }));
  function submit(event: FormEvent<HTMLFormElement>) { event.preventDefault(); if (!values.name.trim()) return; onSubmit(values); }
  return <form className="school-form" onSubmit={submit}><div className="form-grid"><label>Shift name<input value={values.name} onChange={(event) => set("name", event.target.value)} required />{errors?.name && <small className="field-error">{errors.name[0]}</small>}</label><label>Code<input value={values.code} onChange={(event) => set("code", event.target.value)} />{errors?.code && <small className="field-error">{errors.code[0]}</small>}</label><label>Start time<input type="time" value={values.start_time} onChange={(event) => set("start_time", event.target.value)} />{errors?.start_time && <small className="field-error">{errors.start_time[0]}</small>}</label><label>End time<input type="time" value={values.end_time} onChange={(event) => set("end_time", event.target.value)} />{errors?.end_time && <small className="field-error">{errors.end_time[0]}</small>}</label><label className="check-label"><input type="checkbox" checked={values.is_active} onChange={(event) => set("is_active", event.target.checked)} />Active shift</label></div>{errors?._form && <div className="form-error">{errors._form[0]}</div>}<div className="form-actions"><button type="button" className="secondary-button" onClick={onCancel}>Cancel</button><button className="primary-button" disabled={busy}>{busy ? "Saving..." : shift ? "Save changes" : "Add shift"}</button></div></form>;
}
