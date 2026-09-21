"use client";

import { FormEvent, useEffect, useState } from "react";
import { ArrowRight, LockKeyhole, Mail, ShieldCheck } from "lucide-react";
import { useRouter } from "next/navigation";
import { useAuth } from "@/providers/AuthProvider";
import type { Role } from "@/types";

const destinations: Record<Role, string> = { super_admin: "/super-admin", school_admin: "/school", teacher: "/teacher", student: "/student", parent: "/parent" };

export default function LoginPage() {
  const { user, loading, login } = useAuth(); const router = useRouter();
  const [email, setEmail] = useState(""); const [password, setPassword] = useState(""); const [error, setError] = useState(""); const [submitting, setSubmitting] = useState(false);
  useEffect(() => { if (!loading && user) router.replace(destinations[user.role]); }, [loading, user, router]);
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setError(""); setSubmitting(true);
    try { const account = await login(email, password); const next = new URLSearchParams(window.location.search).get("next"); router.replace(next ?? destinations[account.role]); }
    catch (cause) { setError(cause instanceof Error ? cause.message : "Unable to sign in. Check your details and try again."); }
    finally { setSubmitting(false); }
  }
  return <main className="login-page"><section className="login-story"><div className="story-top"><div className="brand brand-light"><span className="brand-mark">S</span><span>Schoolhaus</span></div><span className="story-tag">School operations, clarified</span></div><div className="story-copy"><p className="kicker">A calmer command center</p><h1>Make every school day <em>count.</em></h1><p>One clear place for the people, rhythm, and progress that move your school forward.</p></div><div className="story-footer"><ShieldCheck size={17} /> Your account is protected by Laravel Sanctum</div></section><section className="login-panel"><div className="login-card"><div className="mobile-brand brand"><span className="brand-mark">S</span><span>Schoolhaus</span></div><p className="kicker">Welcome back</p><h2>Sign in to your workspace</h2><p className="muted">Use your school account to continue.</p><form onSubmit={submit} className="auth-form"><label>Email address<span className="input-wrap"><Mail size={17} /><input type="email" value={email} onChange={(event) => setEmail(event.target.value)} placeholder="you@school.edu" required autoComplete="email" /></span></label><label>Password<span className="input-wrap"><LockKeyhole size={17} /><input type="password" value={password} onChange={(event) => setPassword(event.target.value)} placeholder="Enter your password" required autoComplete="current-password" /></span></label>{error && <div className="form-error" role="alert">{error}</div>}<button className="primary-button" type="submit" disabled={submitting}>{submitting ? "Signing you in..." : "Enter workspace"}<ArrowRight size={18} /></button></form><p className="login-note">Access is managed by your school administrator.</p></div></section></main>;
}
