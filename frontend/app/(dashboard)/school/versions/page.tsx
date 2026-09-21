import { AcademicCrudPage } from "@/components/admin/AcademicCrudPage";
export default function VersionsPage() { return <AcademicCrudPage title="Versions" description="Manage curriculum versions for your school." endpoint="versions" responseKey="versions" fields={[{ key: "name", label: "Version name", required: true }, { key: "is_active", label: "Active version", type: "checkbox" }]} />; }
