import PublicAdmissionForm from "@/components/public/PublicAdmissionForm";

export default async function OnlineAdmissionPage({ params }: PageProps<"/admission/[schoolCode]">) {
  const { schoolCode } = await params;
  return <PublicAdmissionForm schoolCode={schoolCode} />;
}
