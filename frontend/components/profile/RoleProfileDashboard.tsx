"use client";

import { useEffect, useState, type FormEvent } from "react";
import { api, ApiRequestError } from "@/lib/api";
import { ErrorState, LoadingState, Notice } from "@/components/admin/AdminStates";
import type { Student, Teacher, User } from "@/types";

type ProfileResponse = { user: User; teacher: Teacher | null; student: Student | null };
type ProfileRole = "teacher" | "student";

export function RoleProfileDashboard({ role }: { role: ProfileRole }) {
  const [profile, setProfile] = useState<ProfileResponse | null>(null);
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const [address, setAddress] = useState("");
  const [photo, setPhoto] = useState<File | null>(null);
  const [currentPassword, setCurrentPassword] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [passwordBusy, setPasswordBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  useEffect(() => {
    const timer = window.setTimeout(() => {
      void api.get<ProfileResponse>("/me/profile").then((result) => {
        setProfile(result);
        setName(result.user.name);
        setPhone(result.teacher?.phone ?? result.student?.phone ?? "");
        setAddress(result.student?.address ?? "");
      }).catch((cause) => {
        setError(cause instanceof ApiRequestError ? cause.message : "Unable to load your profile.");
      }).finally(() => setLoading(false));
    }, 0);
    return () => window.clearTimeout(timer);
  }, []);

  async function saveProfile(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setError("");
    const data = new FormData();
    data.append("_method", "PATCH");
    data.append("name", name);
    data.append("phone", phone);
    if (role === "student") data.append("address", address);
    if (photo) data.append("profile_photo", photo);
    try {
      const result = await api.postForm<ProfileResponse>("/me/profile", data);
      setProfile(result);
      setName(result.user.name);
      setPhone(result.teacher?.phone ?? result.student?.phone ?? "");
      setAddress(result.student?.address ?? "");
      setPhoto(null);
      setNotice("Profile updated successfully.");
    } catch (cause) {
      setError(cause instanceof ApiRequestError ? cause.message : "Unable to update your profile.");
    } finally {
      setBusy(false);
    }
  }

  async function changePassword(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPasswordBusy(true);
    setError("");
    try {
      const result = await api.put<{ message: string }>("/me/password", {
        current_password: currentPassword,
        password,
        password_confirmation: passwordConfirmation,
      });
      setNotice(result.message);
      setCurrentPassword("");
      setPassword("");
      setPasswordConfirmation("");
    } catch (cause) {
      setError(cause instanceof ApiRequestError ? cause.message : "Unable to update your password.");
    } finally {
      setPasswordBusy(false);
    }
  }

  if (loading) return <LoadingState label="Loading your profile..." />;
  if (error && !profile) return <ErrorState message={error} onRetry={() => window.location.reload()} />;
  if (!profile) return null;

  const teacher = role === "teacher" ? profile.teacher : null;
  const student = role === "student" ? profile.student : null;
  const isTeacher = role === "teacher";

  return <>
    <section className="admin-page-heading"><div><p className="kicker">{isTeacher ? "Teacher Dashboard" : "Student Dashboard"}</p><h2>Welcome, {profile.user.name}</h2><p>{isTeacher ? "Your teaching profile and school information." : "Your student profile and school placement."}</p></div></section>
    {notice && <Notice message={notice} />}
    {error && <p className="form-error" role="alert">{error}</p>}
    <section className="detail-card"><p className="kicker">Account profile</p><h3>{profile.user.name}</h3><div className="contact-grid">
      <div><span>{isTeacher ? "Teacher ID" : "Student ID"}</span><strong>{teacher?.employee_id || student?.student_id || "Not assigned"}</strong></div>
      <div><span>Email / Username</span><strong>{profile.user.email || profile.user.username || "Not provided"}</strong></div>
      <div><span>School</span><strong>{profile.user.school?.name || "School"}</strong></div>
      {isTeacher ? <div><span>Designation</span><strong>{teacher?.designation || "Not provided"}</strong></div> : <>
        <div><span>Class</span><strong>{student?.class?.name || "Not assigned"}</strong></div>
        <div><span>Section</span><strong>{student?.section?.name || "Not assigned"}</strong></div>
        <div><span>Roll</span><strong>{student?.roll || "Not assigned"}</strong></div>
        <div><span>Session</span><strong>{student?.academic_year?.name || student?.academicYear?.name || "Not assigned"}</strong></div>
      </>}
    </div></section>
    <section className="split-panels">
      <div className="list-card"><div className="section-heading"><div><p className="kicker">Personal details</p><h3>Update profile</h3></div></div><form className="school-form" onSubmit={saveProfile}><div className="form-grid">
        <label>Full name<input value={name} onChange={(event) => setName(event.target.value)} required /></label>
        <label>Phone<input value={phone} onChange={(event) => setPhone(event.target.value)} /></label>
        {!isTeacher && <label className="wide-field">Address<textarea rows={3} value={address} onChange={(event) => setAddress(event.target.value)} /></label>}
        <label>Profile photo<input type="file" accept="image/jpeg,image/png,image/webp" onChange={(event) => setPhoto(event.target.files?.[0] ?? null)} /></label>
      </div><div className="form-actions"><button className="primary-button" disabled={busy}>{busy ? "Saving..." : "Save profile"}</button></div></form></div>
      <div className="list-card"><div className="section-heading"><div><p className="kicker">Security</p><h3>Change password</h3></div></div><form className="school-form" onSubmit={changePassword}><div className="form-grid">
        <label className="wide-field">Current password<input type="password" value={currentPassword} onChange={(event) => setCurrentPassword(event.target.value)} autoComplete="current-password" required /></label>
        <label>New password<input type="password" value={password} onChange={(event) => setPassword(event.target.value)} autoComplete="new-password" minLength={8} required /></label>
        <label>Confirm new password<input type="password" value={passwordConfirmation} onChange={(event) => setPasswordConfirmation(event.target.value)} autoComplete="new-password" minLength={8} required /></label>
      </div><div className="form-actions"><button className="primary-button" disabled={passwordBusy}>{passwordBusy ? "Updating..." : "Change password"}</button></div></form></div>
    </section>
  </>;
}
