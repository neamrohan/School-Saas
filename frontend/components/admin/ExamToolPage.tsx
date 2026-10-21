"use client";

import Link from "next/link";
import { notFound, useParams } from "next/navigation";

const tools = {
  "exam-type": {
    title: "Exam Type",
    description: "Manage reusable exam categories for this school.",
    dependency: "The current project has no exam-type field, table, or API. Exams are stored by name, class, and academic year, so this page does not invent or misclassify exam data.",
  },
  "exam-routine": {
    title: "Exam Routine",
    description: "Plan exam dates, times, and room assignments.",
    dependency: "Exam records support an overall start and end date, but there is no per-subject schedule, time slot, or room assignment model or API.",
  },
  "exam-seat": {
    title: "Exam Seat",
    description: "Manage student seat assignments for exams.",
    dependency: "The current project has no exam seat plan or student-to-seat assignment table/API.",
  },
  "admit-card": {
    title: "Admit Card",
    description: "Prepare exam admit cards for enrolled students.",
    dependency: "The current project has no admit-card data, generation endpoint, or print layout. Admit cards are not generated from placeholder student or exam data.",
  },
} as const;

export default function ExamToolPage() {
  const { tool } = useParams<{ tool: string }>();
  if (!Object.hasOwn(tools, tool)) notFound();
  const page = tools[tool as keyof typeof tools];

  return (
    <>
      <section className="admin-page-heading">
        <div>
          <p className="kicker">Assessment setup</p>
          <h2>{page.title}</h2>
          <p>{page.description}</p>
        </div>
      </section>
      <section className="list-card">
        <h3>Backend support required</h3>
        <p>{page.dependency}</p>
        <p>
          Existing exam records remain available in <Link href="/school/exams">Exam</Link>.
          Exam management and its school-level data access are unchanged.
        </p>
      </section>
    </>
  );
}
