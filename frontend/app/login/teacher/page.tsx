import LoginForm from "@/app/login/LoginForm";

export default function TeacherLoginPage() {
  return <LoginForm initialRole="teacher" allowedRoles={["teacher"]} />;
}
