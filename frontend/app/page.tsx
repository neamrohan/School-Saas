import PublicSchoolWebsite from "@/components/public/PublicSchoolWebsite";

type HomePageProps = {
  searchParams: Promise<{ school?: string | string[] }>;
};

export default async function HomePage({ searchParams }: HomePageProps) {
  const { school } = await searchParams;
  const initialSchoolCode = Array.isArray(school) ? school[0] : school;

  return <PublicSchoolWebsite key={initialSchoolCode ?? "default"} initialSchoolCode={initialSchoolCode} />;
}
