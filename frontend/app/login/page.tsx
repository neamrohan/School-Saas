import Link from "next/link";
import { ArrowRight, Building2, GraduationCap, Presentation, ShieldCheck } from "lucide-react";

const loginOptions = [
  { href: "/login/school-admin", label: "School Admin", description: "Manage your school's day-to-day operations.", icon: Building2 },
  { href: "/login/teacher", label: "Teacher", description: "Access your classes, attendance, and student tools.", icon: Presentation },
  { href: "/login/student", label: "Student", description: "View your learning, results, and school updates.", icon: GraduationCap },
];

export default function LoginPage() {
  return <main className="login-page">
    <section className="login-story">
      <div className="story-top"><Link className="brand brand-light" href="/"><span className="brand-mark">S</span><span>Schoolhaus</span></Link><span className="story-tag">School operations, clarified</span></div>
      <div className="story-copy"><p className="kicker">A calmer command center</p><h1>Make every school day <em>count.</em></h1><p>One clear place for the people, rhythm, and progress that move your school forward.</p></div>
      <div className="story-footer"><ShieldCheck size={17} /> Secure access for your school community</div>
    </section>
    <section className="login-panel"><div className="login-card login-choice-card">
      <Link className="mobile-brand brand" href="/"><span className="brand-mark">S</span><span>Schoolhaus</span></Link>
      <p className="kicker">Welcome back</p><h2>Choose your login</h2><p className="muted">Select your account type to continue.</p>
      <div className="role-options login-role-options" aria-label="Choose a login type">
        {loginOptions.map(({ href, label, description, icon: Icon }) => <Link className="role-option login-role-link" href={href} key={href}>
          <Icon size={19} /><span><strong>{label}</strong><small>{description}</small></span><ArrowRight className="login-role-arrow" size={17} />
        </Link>)}
      </div>
    </div></section>
  </main>;
}
