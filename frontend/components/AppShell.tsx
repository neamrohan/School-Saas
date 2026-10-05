"use client";

import { useState } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { BarChart3, BookOpen, CalendarDays, ChevronRight, CreditCard, GraduationCap, LayoutDashboard, LogOut, Menu, School, Users, X } from "lucide-react";
import { useAuth } from "@/providers/AuthProvider";
import type { Role } from "@/types";

type NavItem = { label: string; href?: string; icon?: typeof LayoutDashboard; disabled?: boolean };
type NavEntry = NavItem & { children?: Array<{ label: string; href?: string; disabled?: boolean }> };

const nav: Record<Role, NavEntry[]> = {
  super_admin: [{ label: "Dashboard", href: "/super-admin", icon: LayoutDashboard }, { label: "Schools", href: "/super-admin/schools", icon: School }, { label: "Users", href: "/super-admin/users", icon: Users }, { label: "Reports", href: "/super-admin/reports", icon: BarChart3 }, { label: "Profile", href: "/profile", icon: GraduationCap }],
  school_admin: [
    { label: "Dashboard", href: "/school", icon: LayoutDashboard },
    { label: "Teachers", href: "/school/teachers", icon: Users },
    { label: "Students", href: "/school/students", icon: GraduationCap },
    { label: "Parents", href: "/school/parents", icon: Users },
    {
      label: "Academics",
      icon: BookOpen,
      children: [
        { label: "Class", href: "/school/classes" },
        { label: "Shift", href: "/school/shifts" },
        { label: "Version", href: "/school/versions" },
        { label: "Section", href: "/school/sections" },
        { label: "Section Assign to Class", href: "/school/section-assignments" },
        { label: "Group / Trade", href: "/school/groups" },
        { label: "Academic Year", href: "/school/academic-years" },
        { label: "Subject", href: "/school/subjects" },
        { label: "Subject Assign to Class", href: "/school/subject-assignments" },
        { label: "Bulk Student Subject Assignment", href: "/school/bulk-subject-assignments" },
        { label: "Transport", href: "/school/transports" },
        { label: "Class Routine Periods", href: "/school/routine-periods" },
        { label: "Class Routine", href: "/school/class-routine" },
        { label: "Class Routine Room", href: "/school/routine-rooms" },
      ],
    },
    { label: "Attendance", href: "/school/attendance", icon: CalendarDays },
    { label: "Exams & Results", href: "/school/exams", icon: BarChart3 },
    { label: "Exam Subject", href: "/school/exam-subjects", icon: BarChart3 },
    { label: "Marks", href: "/school/marks", icon: BarChart3 },
    { label: "Results", href: "/school/results", icon: BarChart3 },
    { label: "Fees & Payments", icon: CreditCard, children: [
      { label: "Category", href: "/school/fees?tab=categories" },
      { label: "Item", href: "/school/fees?tab=items" },
      { label: "Item Pricing", href: "/school/fees?tab=pricing" },
      { label: "Monthly Item Setup", href: "/school/fees?tab=monthly" },
      { label: "Generate Student Due", href: "/school/fees?tab=generate" },
      { label: "Quick Payment", href: "/school/payments?tab=quick-payment" },
      { label: "Customer Payment", href: "/school/payments?tab=customer-payment" },
      { label: "Payment List", href: "/school/payments?tab=payment-list" },
      { label: "Due List", href: "/school/payments?tab=due-list" },
    ] },
    { label: "Reports", href: "/school/reports", icon: BarChart3 },
    { label: "Profile", href: "/profile", icon: GraduationCap },
  ],
  teacher: [{ label: "Dashboard", href: "/teacher", icon: LayoutDashboard }, { label: "My Classes", href: "/teacher/classes", icon: School }, { label: "My Subjects", href: "/teacher/subjects", icon: BookOpen }, { label: "Attendance", href: "/teacher/attendance", icon: CalendarDays }, { label: "Marks", href: "/teacher/marks", icon: BarChart3 }, { label: "Routine", href: "/teacher/routine", icon: CalendarDays }, { label: "Profile", href: "/profile", icon: GraduationCap }],
  student: [{ label: "Dashboard", href: "/student", icon: LayoutDashboard }, { label: "My Profile", href: "/student/profile", icon: GraduationCap }, { label: "My Classes", href: "/student/classes", icon: School }, { label: "Attendance", href: "/student/attendance", icon: CalendarDays }, { label: "Exams & Results", href: "/student/results", icon: BarChart3 }, { label: "Routine", href: "/student/routine", icon: CalendarDays }, { label: "Fees", href: "/student/fees", icon: CreditCard }],
  parent: [{ label: "Dashboard", href: "/parent", icon: LayoutDashboard }, { label: "My Children", href: "/parent/children", icon: Users }, { label: "Attendance", href: "/parent/attendance", icon: CalendarDays }, { label: "Results", href: "/parent/results", icon: BarChart3 }, { label: "Routine", href: "/parent/routine", icon: CalendarDays }, { label: "Fees", href: "/parent/fees", icon: CreditCard }, { label: "Profile", href: "/profile", icon: GraduationCap }],
};

export function AppShell({ children }: { children: React.ReactNode }) {
  const { user, logout } = useAuth(); const router = useRouter(); const pathname = usePathname(); const searchParams = useSearchParams(); const [open, setOpen] = useState(false);
  const [sections, setSections] = useState<Record<string, boolean>>({ Academics: true, "Fees & Payments": true });
  if (!user) return null;
  const items = nav[user.role];
  const currentQuery = searchParams.toString();
  const isActive = (href?: string) => {
    if (!href) return false;
    const [targetPath, targetQuery] = href.split("?");
    if (targetQuery) return pathname === targetPath && currentQuery === targetQuery;
    if (["/school", "/student", "/teacher", "/super-admin", "/parent"].includes(targetPath)) return pathname === targetPath;
    return pathname === targetPath || pathname.startsWith(`${targetPath}/`);
  };
  const page = items.flatMap((item) => item.children ?? [item]).find((item) => isActive(item.href))?.label ?? "Overview";
  return <div className="app-frame"><div className={`sidebar-overlay ${open ? "sidebar-overlay-visible" : ""}`} onClick={() => setOpen(false)} aria-hidden="true" /><aside className={`sidebar ${open ? "sidebar-open" : ""}`}><div className="brand"><span className="brand-mark">S</span><span>Schoolhaus</span><button className="icon-button sidebar-close" onClick={() => setOpen(false)} aria-label="Close navigation"><X size={18} /></button></div><div className="school-chip"><span className="school-dot" />{user.school?.name ?? "System workspace"}</div><nav className="side-nav" aria-label="Main navigation">{items.map((item) => { const Icon = item.icon; if (item.children) { const expanded = sections[item.label] ?? true; return <div className="nav-section" key={item.label}><button className="nav-section-title" onClick={() => setSections((current) => ({ ...current, [item.label]: !expanded }))} aria-expanded={expanded}>{Icon ? <Icon size={17} /> : null}<span>{item.label}</span><ChevronRight size={15} className={`section-chevron ${expanded ? "section-chevron-open" : ""}`} /></button>{expanded && item.children.map((child) => { const active = isActive(child.href); return <button key={child.label} className={`nav-item nav-child ${active ? "nav-item-active" : ""}`} disabled={child.disabled} aria-current={active ? "page" : undefined} title={child.disabled ? "Coming soon" : child.label} onClick={() => { if (child.href) { router.push(child.href); setOpen(false); } }}><span>{child.label}</span>{active && <ChevronRight size={15} className="nav-chevron" />}{child.disabled && <small>Soon</small>}</button>; })}</div>; } const active = isActive(item.href); return <button key={item.href} className={`nav-item ${active ? "nav-item-active" : ""}`} aria-current={active ? "page" : undefined} title={item.label} onClick={() => { if (item.href) { router.push(item.href); setOpen(false); } }}>{Icon ? <Icon size={18} /> : null}<span>{item.label}</span>{active && <ChevronRight size={15} className="nav-chevron" />}</button>; })}</nav><div className="sidebar-footer"><div className="profile-mini"><div className="avatar">{user.name.charAt(0).toUpperCase()}</div><div><strong>{user.name}</strong><span>{user.role.replace("_", " ")}</span></div></div><button className="logout-button" onClick={() => void logout().then(() => router.replace("/login"))}><LogOut size={17} />Sign out</button></div></aside><div className="shell-main"><header className="topbar"><button className="icon-button menu-button" onClick={() => setOpen(true)} aria-label="Open navigation"><Menu size={21} /></button><div><p className="eyebrow">Workspace / {user.role.replace("_", " ")}</p><h1>{page}</h1></div><div className="topbar-profile"><div className="avatar avatar-small">{user.name.charAt(0).toUpperCase()}</div><div className="header-identity"><strong>{user.name}</strong><small>{user.role.replace("_", " ")}</small></div></div></header><main className="page-content">{children}</main></div></div>;
}