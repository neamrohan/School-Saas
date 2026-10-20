"use client";

import { useEffect, useState } from "react";
import { api, ApiRequestError } from "@/lib/api";
import { EmptyState, ErrorState, LoadingState } from "@/components/admin/AdminStates";
import type { TeacherAssignment } from "@/types";

type AssignmentView = "classes" | "subjects";

export function TeacherAssignmentsPage({ view }: { view: AssignmentView }) {
  const [assignments, setAssignments] = useState<TeacherAssignment[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  async function load() {
    setLoading(true);
    setError("");
    try {
      const result = await api.get<{ assignments: TeacherAssignment[] }>("/teacher/assignments");
      setAssignments(result.assignments);
    } catch (cause) {
      setError(cause instanceof ApiRequestError ? cause.message : "Unable to load your teaching assignments.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timer);
  }, []);

  if (loading) return <LoadingState label={`Loading your ${view}...`} />;
  if (error) return <ErrorState message={error} onRetry={() => void load()} />;

  const title = view === "classes" ? "My Classes" : "My Subjects";
  const uniqueAssignments = view === "classes"
    ? assignments.filter((assignment, index, all) => all.findIndex((item) =>
      item.class_id === assignment.class_id && item.section_id === assignment.section_id,
    ) === index)
    : assignments;

  return (
    <>
      <section className="admin-page-heading">
        <div>
          <p className="kicker">Teaching assignments</p>
          <h2>{title}</h2>
          <p>{view === "classes" ? "Classes and sections assigned to you." : "Subjects you teach in each class and section."}</p>
        </div>
      </section>
      <section className="list-card">
        {uniqueAssignments.length ? (
          <div className="data-table-wrap">
            <table className="data-table">
              <thead><tr>
                <th>Class</th>
                <th>Section</th>
                {view === "subjects" && <th>Subject</th>}
              </tr></thead>
              <tbody>
                {uniqueAssignments.map((assignment) => (
                  <tr key={view === "classes" ? `${assignment.class_id}-${assignment.section_id}` : assignment.id}>
                    <td>{assignment.class?.name ?? `Class #${assignment.class_id}`}</td>
                    <td>{assignment.section?.name ?? `Section #${assignment.section_id}`}</td>
                    {view === "subjects" && <td>{assignment.subject?.name ?? `Subject #${assignment.subject_id}`}</td>}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <EmptyState title="No assignments yet" copy="Your school administrator has not assigned any classes or subjects to you." />
        )}
      </section>
    </>
  );
}
