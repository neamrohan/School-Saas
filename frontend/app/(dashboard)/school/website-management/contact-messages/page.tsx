"use client";

import { useCallback, useEffect, useState } from "react";
import { usePathname } from "next/navigation";
import { Check, Trash2 } from "lucide-react";
import { api, ApiRequestError } from "@/lib/api";
import { EmptyState, ErrorState, LoadingState, Notice } from "@/components/admin/AdminStates";

type ContactMessage = {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  message: string;
  read_at: string | null;
  created_at: string;
};

export default function ContactMessagesPage() {
  const pathname = usePathname();
  const superAdminSchoolId = pathname.match(/^\/super-admin\/schools\/(\d+)\/website-management\/contact-messages$/)?.[1];
  const messagesEndpoint = superAdminSchoolId ? `/schools/${superAdminSchoolId}/contact-messages` : "/school/contact-messages";
  const [messages, setMessages] = useState<ContactMessage[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [noticeTone, setNoticeTone] = useState<"success" | "error">("success");
  const [busyId, setBusyId] = useState<number | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const result = await api.get<{ messages: { data: ContactMessage[] } }>(messagesEndpoint);
      setMessages(result.messages.data);
    } catch (cause) {
      setError(cause instanceof ApiRequestError ? cause.message : "Unable to load contact messages.");
    } finally {
      setLoading(false);
    }
  }, [messagesEndpoint]);

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  async function markRead(message: ContactMessage) {
    setBusyId(message.id);
    setNotice("");
    try {
      await api.patch(`${messagesEndpoint}/${message.id}/read`, {});
      setMessages((current) => current.map((item) => item.id === message.id ? { ...item, read_at: new Date().toISOString() } : item));
      setNotice("Message marked as read.");
      setNoticeTone("success");
    } catch (cause) {
      setNotice(cause instanceof ApiRequestError ? cause.message : "Unable to update the message.");
      setNoticeTone("error");
    } finally {
      setBusyId(null);
    }
  }

  async function remove(message: ContactMessage) {
    if (!window.confirm(`Delete the message from ${message.name}?`)) return;
    setBusyId(message.id);
    setNotice("");
    try {
      await api.delete(`${messagesEndpoint}/${message.id}`);
      setMessages((current) => current.filter((item) => item.id !== message.id));
      setNotice("Message deleted.");
      setNoticeTone("success");
    } catch (cause) {
      setNotice(cause instanceof ApiRequestError ? cause.message : "Unable to delete the message.");
      setNoticeTone("error");
    } finally {
      setBusyId(null);
    }
  }

  if (loading) return <LoadingState label="Loading contact messages..." />;
  if (error) return <ErrorState message={error} onRetry={() => void load()} />;

  return <>
    <section className="admin-page-heading"><div><p className="kicker">Website Management</p><h2>Contact messages</h2><p>Messages submitted through this public school website.</p></div></section>
    {notice && <Notice message={notice} tone={noticeTone} />}
    <section className="list-card">
      {messages.length ? <div className="contact-message-list">{messages.map((message) => <article className={`contact-message-card ${message.read_at ? "" : "contact-message-unread"}`} key={message.id}>
        <header><div><h3>{message.name}</h3><a href={`mailto:${message.email}`}>{message.email}</a>{message.phone && <span>{message.phone}</span>}</div><time dateTime={message.created_at}>{new Date(message.created_at).toLocaleString()}</time></header>
        <p>{message.message}</p>
        <footer>{!message.read_at && <button className="secondary-button compact-button" disabled={busyId !== null} onClick={() => void markRead(message)}><Check size={15} />Mark read</button>}<button className="danger-button compact-button" disabled={busyId !== null} onClick={() => void remove(message)}><Trash2 size={15} />Delete</button></footer>
      </article>)}</div> : <EmptyState title="No contact messages" copy="Messages sent through your school website will appear here." />}
    </section>
  </>;
}
