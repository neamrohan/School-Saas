"use client";

import { useEffect, useState } from "react";
import { api, ApiRequestError } from "@/lib/api";
import { EmptyState, ErrorState, LoadingState } from "@/components/admin/AdminStates";
import type { Routine } from "@/types";

const days = ["saturday", "sunday", "monday", "tuesday", "wednesday", "thursday", "friday"];

export default function TeacherRoutinePage() {
  const [routines, setRoutines] = useState<Routine[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  async function load() {
    setLoading(true);
    setError("");
    try {
      const result = await api.get<{ routines: Routine[] }>("/teacher/routines");
      setRoutines(result.routines);
    } catch (cause) {
      setError(cause instanceof ApiRequestError ? cause.message : "Unable to load your routine.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timer);
  }, []);

  if (loading) return <LoadingState label="Loading your routine..." />;
  if (error) return <ErrorState message={error} onRetry={() => void load()} />;

  return (
    <>
      <section className="admin-page-heading">
        <div><p className="kicker">Your teaching schedule</p><h2>Routine</h2><p>Weekly timetable for your assigned classes.</p></div>
      </section>
      <section className="list-card">
        {routines.length ? (
          <div className="data-table-wrap"><table className="data-table">
            <thead><tr><th>Day</th><th>Time</th><th>Class / Section</th><th>Subject</th><th>Room</th></tr></thead>
            <tbody>{days.flatMap((day) => routines.filter((item) => item.day_of_week === day).map((item) => (
              <tr key={item.id}>
                <td>{day.charAt(0).toUpperCase() + day.slice(1)}</td>
                <td>{item.period?.name ? `${item.period.name} · ` : ""}{item.start_time.slice(0, 5)}–{item.end_time.slice(0, 5)}</td>
                <td>{item.class?.name ?? `Class #${item.class_id}`} · {item.section?.name ?? `Section #${item.section_id}`}</td>
                <td>{item.subject?.name ?? `Subject #${item.subject_id}`}</td>
                <td>{item.roomEntity?.name ?? item.room ?? "—"}</td>
              </tr>
            )))}</tbody>
          </table></div>
        ) : (
          <EmptyState title="No routine published" copy="Your schedule will appear here once your school administrator adds your classes to the routine." />
        )}
      </section>
    </>
  );
}
