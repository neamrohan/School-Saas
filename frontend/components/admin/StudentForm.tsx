import { useState, type FormEvent } from "react";
import Link from "next/link";
import type { AcademicYear, GroupTrade, SchoolClass, Section, Shift, Student, Version } from "@/types";

export interface StudentFormValues {
  name: string;
  email: string;
  username: string;
  password: string;
  password_confirmation: string;
  student_id: string;
  roll: string;
  phone: string;
  date_of_birth: string;
  gender: string;
  address: string;
  blood_group: string;
  admission_date: string;
  class_id: string;
  section_id: string;
  academic_year_id: string;
  shift_id: string;
  version_id: string;
  group_trade_id: string;
  is_active: boolean;
  profile_photo: File | null;
}

export function StudentForm({ student, classes, sections, academicYears, shifts, versions, groups, busy, errors, onSubmit, onCancel }: {
  student?: Student | null;
  classes: SchoolClass[];
  sections: Section[];
  academicYears: AcademicYear[];
  shifts: Shift[];
  versions: Version[];
  groups: GroupTrade[];
  busy: boolean;
  errors?: Record<string, string[]>;
  onSubmit: (values: StudentFormValues) => void;
  onCancel: () => void;
}) {
  const [classId, setClassId] = useState(student?.class_id ? String(student.class_id) : "");
  const [formError, setFormError] = useState("");
  const availableSections = sections.filter((section) => !classId || String(section.school_class_id) === classId);

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    const email = String(data.get("email") ?? "").trim();
    const username = String(data.get("username") ?? "").trim();
    if (!student && !email && !username) {
      setFormError("Enter an email address or username.");
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
      student_id: String(data.get("student_id") ?? "").trim(),
      roll: String(data.get("roll") ?? "").trim(),
      phone: String(data.get("phone") ?? "").trim(),
      date_of_birth: String(data.get("date_of_birth") ?? ""),
      gender: String(data.get("gender") ?? "").trim(),
      address: String(data.get("address") ?? "").trim(),
      blood_group: String(data.get("blood_group") ?? "").trim(),
      admission_date: String(data.get("admission_date") ?? ""),
      class_id: String(data.get("class_id") ?? ""),
      section_id: String(data.get("section_id") ?? ""),
      academic_year_id: String(data.get("academic_year_id") ?? ""),
      shift_id: String(data.get("shift_id") ?? ""),
      version_id: String(data.get("version_id") ?? ""),
      group_trade_id: String(data.get("group_trade_id") ?? ""),
      is_active: data.has("is_active") ? data.get("is_active") === "1" : true,
      profile_photo: photo instanceof File && photo.size > 0 ? photo : null,
    });
  }

  return <form className="school-form" onSubmit={submit}>
    <div className="form-grid">
      <label className="wide-field">Full name<input name="name" defaultValue={student?.user?.name ?? ""} required />{errors?.name && <small className="field-error">{errors.name[0]}</small>}</label>
      <label>Email<input name="email" type="email" defaultValue={student?.user?.email ?? ""} autoComplete="email" />{errors?.email && <small className="field-error">{errors.email[0]}</small>}</label>
      <label>Username<input name="username" defaultValue={student?.user?.username ?? ""} autoComplete="username" />{errors?.username && <small className="field-error">{errors.username[0]}</small>}</label>
      <label>{student ? "New password (optional)" : "Password"}<input name="password" type="password" autoComplete="new-password" minLength={8} required={!student} />{errors?.password && <small className="field-error">{errors.password[0]}</small>}</label>
      <label>Confirm password<input name="password_confirmation" type="password" autoComplete="new-password" minLength={8} required={!student} /></label>
      <label>Student ID<input name="student_id" defaultValue={student?.student_id ?? ""} /></label>
      <label>Roll<input name="roll" defaultValue={student?.roll ?? ""} /></label>
      <label>Phone<input name="phone" defaultValue={student?.phone ?? ""} /></label>
      <label>Class<select name="class_id" value={classId} onChange={(event) => setClassId(event.target.value)}><option value="">Unassigned</option>{classes.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
      <label>Section<select name="section_id" defaultValue={student?.section_id ?? ""} disabled={!classId}><option value="">Unassigned</option>{availableSections.map((section) => <option key={section.id} value={section.id}>{section.name}</option>)}</select></label>
      {classes.length === 0 && <p className="wide-field muted">No classes are available yet. <Link href="/school/classes" className="text-link">Create a class</Link> first.</p>}
      {classId && availableSections.length === 0 && <p className="wide-field muted">No sections exist for this class. <Link href="/school/sections" className="text-link">Create a section</Link> first.</p>}
      <label>Session<select name="academic_year_id" defaultValue={student?.academic_year_id ?? ""} required><option value="">Select session</option>{academicYears.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select>{errors?.academic_year_id && <small className="field-error">{errors.academic_year_id[0]}</small>}</label>
      <label>Shift<select name="shift_id" defaultValue={student?.shift_id ?? ""}><option value="">Not assigned</option>{shifts.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
      <label>Version<select name="version_id" defaultValue={student?.version_id ?? ""}><option value="">Not assigned</option>{versions.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
      <label>Group / Trade<select name="group_trade_id" defaultValue={student?.group_trade_id ?? ""}><option value="">Not assigned</option>{groups.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
      <label>Date of birth<input name="date_of_birth" type="date" defaultValue={student?.date_of_birth?.slice(0, 10) ?? ""} /></label>
      <label>Gender<input name="gender" defaultValue={student?.gender ?? ""} /></label>
      <label>Blood group<input name="blood_group" defaultValue={student?.blood_group ?? ""} /></label>
      <label>Admission date<input name="admission_date" type="date" defaultValue={student?.admission_date?.slice(0, 10) ?? ""} /></label>
      <label>Profile photo<input name="profile_photo" type="file" accept="image/jpeg,image/png,image/webp" /></label>
      {student && <label>Account status<select name="is_active" defaultValue={student.user?.is_active === false || student.is_active === false ? "0" : "1"}><option value="1">Active</option><option value="0">Inactive</option></select></label>}
      <label className="wide-field">Address<textarea name="address" rows={3} defaultValue={student?.address ?? ""} /></label>
    </div>
    {(formError || errors?._form) && <div className="form-error" role="alert">{formError || errors?._form?.[0]}</div>}
    <div className="form-actions"><button type="button" className="secondary-button" onClick={onCancel}>Cancel</button><button type="submit" className="primary-button" disabled={busy}>{busy ? "Saving..." : student ? "Save changes" : "Create student account"}</button></div>
  </form>;
}