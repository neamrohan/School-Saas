"use client";

import { useEffect, useState, type FormEvent } from "react";
import Link from "next/link";
import { ArrowLeft, ArrowRight, Building2, Eye, EyeOff, GraduationCap, LockKeyhole, Mail, Presentation, ShieldCheck, ShieldUser } from "lucide-react";
import { useRouter } from "next/navigation";
import { useAuth } from "@/providers/AuthProvider";
import type { Role } from "@/types";

const destinations: Record<Role, string> = { super_admin: "/super-admin", school_admin: "/school", teacher: "/teacher", student: "/student", parent: "/parent" };
const loginRoles = [
  { role: "super_admin", label: "Super Admin", icon: ShieldUser },
  { role: "school_admin", label: "School Admin", icon: Building2 },
  { role: "teacher", label: "Teacher", icon: Presentation },
  { role: "student", label: "Student", icon: GraduationCap },
] as const;
export type LoginRole = (typeof loginRoles)[number]["role"];
const roleHeadings: Record<LoginRole, string> = {
  super_admin: "Super Admin Login",
  school_admin: "School Admin Login",
  teacher: "Teacher Login",
  student: "Student Login",
};

export default function LoginForm({ initialRole, allowedRoles }: {
  initialRole: LoginRole;
  allowedRoles: LoginRole[];
}) {
  const { user, loading, login } = useAuth(); const router = useRouter();
  const availableRoles = loginRoles.filter(({ role }) => allowedRoles.includes(role));
  const selectedRole = initialRole;
  const roleLabel = availableRoles.find(({ role }) => role === selectedRole)?.label;
  const [email, setEmail] = useState(""); const [password, setPassword] = useState(""); const [showPassword, setShowPassword] = useState(false); const [error, setError] = useState(""); const [submitting, setSubmitting] = useState(false);
  useEffect(() => { if (!loading && user) router.replace(destinations[user.role]); }, [loading, user, router]);
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setError("");
    setSubmitting(true);
    try { const account = await login(email, password, selectedRole); router.replace(destinations[account.role]); }
    catch (cause) { setError(cause instanceof TypeError ? "Unable to reach the server. Check your connection and try again." : cause instanceof Error ? cause.message : "Unable to sign in. Check your details and try again."); }
    finally { setSubmitting(false); }
  }
  if (!roleLabel) return <main className="login-page"><section className="login-panel"><p role="alert">This login page is not available.</p></section></main>;

  return <main className="login-page">
    <section className="login-story">
      <div className="story-top"><div className="brand brand-light"><span className="brand-mark">S</span><span>Schoolhaus</span></div><span className="story-tag">School operations, clarified</span></div>
      <div className="story-copy"><p className="kicker">A calmer command center</p><h1>Make every school day <em>count.</em></h1><p>One clear place for the people, rhythm, and progress that move your school forward.</p></div>
      <div className="story-footer"><ShieldCheck size={17} /> Your account is protected by Laravel Sanctum</div>
    </section>
    <section className="login-panel"><div className="login-card">
      <div className="mobile-brand brand"><span className="brand-mark">S</span><span>Schoolhaus</span></div>
      <p className="kicker">Welcome back</p><h2>{roleHeadings[selectedRole]}</h2>
      <p className="muted">Sign in to your {roleLabel} workspace.</p>
      <p className="selected-role">Signing in as <strong>{roleLabel}</strong></p>
      <form onSubmit={submit} className="auth-form">
        <label>Username or email<span className="input-wrap"><Mail size={17} /><input type="text" value={email} onChange={(event) => setEmail(event.target.value)} placeholder="Enter your username or email" required autoComplete="username" /></span></label>
        <label>Password<span className="input-wrap"><LockKeyhole size={17} /><input type={showPassword ? "text" : "password"} value={password} onChange={(event) => setPassword(event.target.value)} placeholder="Enter your password" required autoComplete="current-password" /><button type="button" className="password-toggle" onClick={() => setShowPassword((visible) => !visible)} aria-label={showPassword ? "Hide password" : "Show password"} aria-pressed={showPassword}>{showPassword ? <EyeOff size={17} /> : <Eye size={17} />}</button></span></label>
        {error && <div className="form-error" role="alert">{error}</div>}
        <button className="primary-button" type="submit" disabled={submitting}>{submitting ? "Signing you in..." : "Login"}<ArrowRight size={18} /></button>
      </form>
      <Link className="login-back-link" href="/login"><ArrowLeft size={16} />Back to Login</Link>
    </div></section>
  </main>;
}
