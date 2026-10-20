import LoginForm from "@/app/login/LoginForm";

export default function SchoolAdminLoginPage() {
  return <LoginForm initialRole="school_admin" allowedRoles={["school_admin"]} />;
}
