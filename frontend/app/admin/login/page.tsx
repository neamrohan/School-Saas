import LoginForm from "@/app/login/LoginForm";

export default function SuperAdminLoginPage() {
  return <LoginForm initialRole="super_admin" allowedRoles={["super_admin"]} />;
}
