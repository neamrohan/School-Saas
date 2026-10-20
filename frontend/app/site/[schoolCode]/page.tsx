import { redirect } from "next/navigation";

type SchoolSitePageProps = {
  params: Promise<{ schoolCode: string }>;
};

export default async function SchoolSitePage({ params }: SchoolSitePageProps) {
  const { schoolCode } = await params;

  redirect(`/?school=${encodeURIComponent(schoolCode)}`);
}
