import LoginForm from "@/app/login/LoginForm";

export default function StudentLoginPage() {
  return <LoginForm initialRole="student" allowedRoles={["student"]} />;
}
