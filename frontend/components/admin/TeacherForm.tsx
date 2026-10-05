import { useState, type FormEvent } from "react";
import type { SchoolClass, Section, Subject, Teacher } from "@/types";

export interface TeacherFormValues {
  name: string;
  email: string;
  username: string;
  password: string;
  password_confirmation: string;
  employee_id: string;
  phone: string;
  designation: string;
  qualification: string;
  joining_date: string;
  class_id: string;
  section_id: string;
  subject_id: string;
  is_active: boolean;
  profile_photo: File | null;
}

export function TeacherForm({ teacher, classes, sections, subjects, busy, errors, onSubmit, onCancel }: {
  teacher?: Teacher | null;
  classes: SchoolClass[];
  sections: Section[];
  subjects: Subject[];
  busy: boolean;
  errors?: Record<string, string[]>;
  onSubmit: (values: TeacherFormValues) => void;
  onCancel: () => void;
}) {
  const assignment = teacher?.assignments?.[0];
  const [classId, setClassId] = useState(assignment ? String(assignment.class_id) : "");
  const [formError, setFormError] = useState("");
  const availableSections = sections.filter((section) => !classId || section.school_class_id === Number(classId));

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    const email = String(data.get("email") ?? "").trim();
    const username = String(data.get("username") ?? "").trim();
    const assignmentValues = ["class_id", "section_id", "subject_id"].map((key) => String(data.get(key) ?? ""));
    if (!teacher && !email && !username) {
      setFormError("Enter an email address or username.");
      return;
    }
    if (assignmentValues.some(Boolean) && !assignmentValues.every(Boolean)) {
      setFormError("Select a class, section, and subject to add an assignment.");
      return;
    }
    setFormError("");
    const photo = data.get("profile_photo");
    onSubmit({
      name: String(data.get("name") ?? "").trim(),
      email,
      username,
      password: String(data.get("password") ?? ""),
      password_confirmation: String(data.get("password_confirmation") ?? ""),
      employee_id: String(data.get("employee_id") ?? "").trim(),
      phone: String(data.get("phone") ?? "").trim(),
      designation: String(data.get("designation") ?? "").trim(),
      qualification: String(data.get("qualification") ?? "").trim(),
      joining_date: String(data.get("joining_date") ?? ""),
      class_id: String(data.get("class_id") ?? ""),
      section_id: String(data.get("section_id") ?? ""),
      subject_id: String(data.get("subject_id") ?? ""),
      is_active: data.has("is_active") ? data.get("is_active") === "1" : true,
      profile_photo: photo instanceof File && photo.size > 0 ? photo : null,
    });
  }

  return <form className="school-form" onSubmit={submit}>
    <div className="form-grid">
      <label className="wide-field">Full name<input name="name" defaultValue={teacher?.user?.name ?? ""} required />{errors?.name && <small className="field-error">{errors.name[0]}</small>}</label>
      <label>Email<input name="email" type="email" defaultValue={teacher?.user?.email ?? ""} autoComplete="email" />{errors?.email && <small className="field-error">{errors.email[0]}</small>}</label>
      <label>Username<input name="username" defaultValue={teacher?.user?.username ?? ""} autoComplete="username" />{errors?.username && <small className="field-error">{errors.username[0]}</small>}</label>
      <label>{teacher ? "New password (optional)" : "Password"}<input name="password" type="password" autoComplete="new-password" minLength={8} required={!teacher} />{errors?.password && <small className="field-error">{errors.password[0]}</small>}</label>
      <label>Confirm password<input name="password_confirmation" type="password" autoComplete="new-password" minLength={8} required={!teacher} /></label>
      <label>Teacher ID<input name="employee_id" defaultValue={teacher?.employee_id ?? ""} /></label>
      <label>Phone<input name="phone" defaultValue={teacher?.phone ?? ""} /></label>
      <label>Designation<input name="designation" defaultValue={teacher?.designation ?? ""} /></label>
      <label>Qualification<input name="qualification" defaultValue={teacher?.qualification ?? ""} /></label>
      <label>Joining date<input name="joining_date" type="date" defaultValue={teacher?.joining_date?.slice(0, 10) ?? ""} /></label>
      <label>Class<select name="class_id" value={classId} onChange={(event) => setClassId(event.target.value)}><option value="">No class assignment</option>{classes.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
      <label>Section<select name="section_id" defaultValue={assignment?.section_id ?? ""} disabled={!classId}><option value="">Select section</option>{availableSections.map((section) => <option key={section.id} value={section.id}>{section.name}</option>)}</select></label>
      <label>Subject<select name="subject_id" defaultValue={assignment?.subject_id ?? ""}><option value="">Select subject</option>{subjects.map((subject) => <option key={subject.id} value={subject.id}>{subject.name}</option>)}</select></label>
      <label>Profile photo<input name="profile_photo" type="file" accept="image/jpeg,image/png,image/webp" /></label>
      {teacher && <label>Account status<select name="is_active" defaultValue={teacher.user?.is_active === false ? "0" : "1"}><option value="1">Active</option><option value="0">Inactive</option></select></label>}
    </div>
    {(formError || errors?._form) && <div className="form-error" role="alert">{formError || errors?._form?.[0]}</div>}
    <div className="form-actions"><button type="button" className="secondary-button" onClick={onCancel}>Cancel</button><button type="submit" className="primary-button" disabled={busy}>{busy ? "Saving..." : teacher ? "Save changes" : "Create teacher account"}</button></div>
  </form>;
}