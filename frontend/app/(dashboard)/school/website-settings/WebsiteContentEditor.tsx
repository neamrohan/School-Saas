"use client";

import Image from "next/image";
import { ArrowDown, ArrowUp, Plus, Trash2 } from "lucide-react";
import { useEffect, useRef, useState, type ChangeEvent } from "react";
import type { WebsiteContent, WebsiteContentEntry, WebsiteGalleryItem, WebsiteSectionKey, WebsiteStaffProfile } from "@/types";

const websiteSections: Array<{ key: WebsiteSectionKey; label: string }> = [
  { key: "about", label: "About" },
  { key: "welcome", label: "Welcome" },
  { key: "statistics", label: "Statistics" },
  { key: "mission", label: "Mission and vision" },
  { key: "principal", label: "Principal message" },
  { key: "history", label: "School history" },
  { key: "facilities", label: "Facilities" },
  { key: "programs", label: "Academic programs" },
  { key: "achievements", label: "Achievements" },
  { key: "gallery", label: "Gallery" },
  { key: "admissions", label: "Admissions" },
  { key: "staff", label: "Staff profiles" },
  { key: "notices", label: "Notices" },
  { key: "news", label: "News and events" },
  { key: "contact", label: "Contact" },
];

type Props = {
  content: WebsiteContent;
  galleryFiles: Record<string, File>;
  profileFiles: Record<string, File>;
  fieldErrors: Record<string, string[]>;
  onChange: (content: WebsiteContent) => void;
  onGalleryFile: (id: string, file: File | null) => void;
  onProfileFile: (id: string, file: File | null) => void;
};

export function WebsiteContentEditor({ content, galleryFiles, profileFiles, fieldErrors, onChange, onGalleryFile, onProfileFile }: Props) {
  const [galleryPreviews, setGalleryPreviews] = useState<Record<string, string>>({});
  const previewUrls = useRef<Record<string, string>>({});

  useEffect(() => () => Object.values(previewUrls.current).forEach((url) => URL.revokeObjectURL(url)), []);

  function update<K extends keyof WebsiteContent>(key: K, value: WebsiteContent[K]) {
    onChange({ ...content, [key]: value });
  }

  function updateEntry(key: "notices" | "news", id: string, changes: Partial<WebsiteContentEntry>) {
    update(key, content[key].map((item) => item.id === id ? { ...item, ...changes } : item));
  }

  function updateGallery(id: string, changes: Partial<WebsiteGalleryItem>) {
    update("gallery", content.gallery.map((item) => item.id === id ? { ...item, ...changes } : item));
  }

  function updateStaff(id: string, changes: Partial<WebsiteStaffProfile>) {
    update("staff", content.staff.map((item) => item.id === id ? { ...item, ...changes } : item));
  }

  function moveSection(index: number, direction: -1 | 1) {
    const target = index + direction;
    if (target < 0 || target >= content.section_order.length) return;
    if (content.section_order[index] === "about" || content.section_order[index] === "contact"
      || content.section_order[target] === "about" || content.section_order[target] === "contact") return;
    const order = [...content.section_order];
    [order[index], order[target]] = [order[target], order[index]];
    update("section_order", order);
  }

  function newId(prefix: string): string {
    return `${prefix}-${globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(16).slice(2)}`}`;
  }

  function addEntry(key: "notices" | "news") {
    const item: WebsiteContentEntry = {
      id: newId(key === "news" ? "event" : "notice"),
      title: "",
      description: "",
      date: "",
      status: "draft",
      is_demo: true,
    };
    update(key, [...content[key], item]);
  }

  function addGalleryItem() {
    update("gallery", [...content.gallery, {
      id: newId("gallery"),
      title: "",
      description: "",
      image_url: null,
      is_active: true,
      status: "draft",
    }]);
  }

  function changeGalleryImage(event: ChangeEvent<HTMLInputElement>, itemId: string) {
    const file = event.target.files?.[0] ?? null;
    if (previewUrls.current[itemId]) URL.revokeObjectURL(previewUrls.current[itemId]);
    const url = file ? URL.createObjectURL(file) : null;
    if (url) previewUrls.current[itemId] = url;
    else delete previewUrls.current[itemId];
    setGalleryPreviews((current) => {
      const next = { ...current };
      if (url) next[itemId] = url;
      else delete next[itemId];
      return next;
    });
    onGalleryFile(itemId, file);
  }

  function removeGalleryItem(itemId: string) {
    if (previewUrls.current[itemId]) {
      URL.revokeObjectURL(previewUrls.current[itemId]);
      delete previewUrls.current[itemId];
    }
    setGalleryPreviews((current) => {
      const next = { ...current };
      delete next[itemId];
      return next;
    });
    onGalleryFile(itemId, null);
    update("gallery", content.gallery.filter((photo) => photo.id !== itemId));
  }

  return (
    <div className="website-content-editor">
      <section className="list-card website-settings-section">
        <header className="website-editor-section-heading"><div><h3>Header navigation</h3><p>Choose section anchors from this school website.</p></div><button className="secondary-button" type="button" onClick={() => update("navigation", [...content.navigation, { id: newId("nav"), label: "", href: "#about" }])}><Plus size={15} />Add link</button></header>
        <div className="website-editor-list">{content.navigation.map((item) => <div className="website-editor-row" key={item.id}>
          <label>Label<input maxLength={50} value={item.label} onChange={(event) => update("navigation", content.navigation.map((link) => link.id === item.id ? { ...link, label: event.target.value } : link))} /></label>
          <label>Section target<select value={item.href} onChange={(event) => update("navigation", content.navigation.map((link) => link.id === item.id ? { ...link, href: event.target.value } : link))}>{websiteSections.map((section) => <option key={section.key} value={`#${section.key}`}>{section.label}</option>)}</select></label>
          <button className="danger-button website-remove-button" type="button" aria-label={`Remove ${item.label || "navigation"} link`} onClick={() => update("navigation", content.navigation.filter((link) => link.id !== item.id))}><Trash2 size={15} /></button>
        </div>)}</div>
      </section>

      <section className="list-card website-settings-section">
        <h3>Homepage section visibility and order</h3>
        <div className="website-section-order-list">{content.section_order.map((key, index) => {
          const section = websiteSections.find((item) => item.key === key);
          if (!section) return null;
          const visible = content.visible_sections.includes(key);
          const fixedPosition = key === "about" || key === "contact";
          return <div className="website-section-order-row" key={key}>
            <label className="website-inline-checkbox"><input type="checkbox" checked={visible} onChange={(event) => update("visible_sections", event.target.checked ? [...content.visible_sections, key] : content.visible_sections.filter((item) => item !== key))} />{section.label}</label>
            <span>{fixedPosition ? "Fixed position" : `Position ${index}`}</span>
            <button className="icon-button" type="button" aria-label={`Move ${section.label} up`} disabled={fixedPosition || key === content.section_order[1]} onClick={() => moveSection(index, -1)}><ArrowUp size={15} /></button>
            <button className="icon-button" type="button" aria-label={`Move ${section.label} down`} disabled={fixedPosition || key === content.section_order[content.section_order.length - 2]} onClick={() => moveSection(index, 1)}><ArrowDown size={15} /></button>
          </div>;
        })}</div>
      </section>

      <section className="list-card website-settings-section">
        <header className="website-editor-section-heading"><div><h3>Homepage and about</h3><p>Set the section status to Draft to hide these website sections until you publish.</p></div><label>Section status<select value={content.status} onChange={(event) => update("status", event.target.value as WebsiteContent["status"])}><option value="draft">Draft</option><option value="published">Published</option></select></label></header>
        <div className="form-grid">
          <TextArea label="Welcome message" value={content.welcome_message} onChange={(value) => update("welcome_message", value)} />
          <TextArea label="School history" value={content.school_history} onChange={(value) => update("school_history", value)} />
          <TextArea label="Mission" value={content.mission} onChange={(value) => update("mission", value)} />
          <TextArea label="Vision" value={content.vision} onChange={(value) => update("vision", value)} />
          <TextArea label="Admission information" value={content.admission_information} onChange={(value) => update("admission_information", value)} />
        </div>
      </section>

      <section className="list-card website-settings-section">
        <h3>Principal’s message</h3>
        <div className="form-grid">
          <label>Principal name<input maxLength={255} value={content.principal_message.name} onChange={(event) => onChange({ ...content, principal_message: { ...content.principal_message, name: event.target.value } })} /></label>
          <label>Designation<input maxLength={255} value={content.principal_message.designation} onChange={(event) => onChange({ ...content, principal_message: { ...content.principal_message, designation: event.target.value } })} /></label>
          <TextArea label="Principal’s message" value={content.principal_message.message} onChange={(value) => onChange({ ...content, principal_message: { ...content.principal_message, message: value } })} />
          {content.principal_message.image_url && <div className="website-profile-image"><Image src={content.principal_message.image_url} alt="" width={84} height={84} unoptimized /></div>}
          <label>Replace principal photo<input type="file" accept="image/png,image/jpeg,image/webp" onChange={(event) => onProfileFile("principal", event.target.files?.[0] ?? null)} /></label>
          {profileFiles.principal && <small>{profileFiles.principal.name}</small>}
        </div>
      </section>

      <section className="list-card website-settings-section">
        <h3>Facilities and achievements</h3>
        <div className="form-grid">
          <EditableList title="Facilities" items={content.facilities} onChange={(items) => update("facilities", items)} />
          <EditableList title="Academic programs" items={content.academic_programs} onChange={(items) => update("academic_programs", items)} />
          <EditableList title="Achievements" items={content.achievements} onChange={(items) => update("achievements", items)} />
        </div>
      </section>

      <section className="list-card website-settings-section">
        <header className="website-editor-section-heading"><h3>School statistics</h3><button className="secondary-button" type="button" onClick={() => update("statistics", [...content.statistics, { label: "", value: "" }])}><Plus size={15} />Add statistic</button></header>
        <div className="website-editor-list">{content.statistics.map((item, index) => <div className="website-editor-row" key={`stat-${index}`}>
          <label>Label<input maxLength={100} value={item.label} onChange={(event) => update("statistics", content.statistics.map((entry, entryIndex) => entryIndex === index ? { ...entry, label: event.target.value } : entry))} /></label>
          <label>Value<input maxLength={100} value={item.value} onChange={(event) => update("statistics", content.statistics.map((entry, entryIndex) => entryIndex === index ? { ...entry, value: event.target.value } : entry))} /></label>
          <button className="danger-button website-remove-button" type="button" aria-label="Remove statistic" onClick={() => update("statistics", content.statistics.filter((_, entryIndex) => entryIndex !== index))}><Trash2 size={15} /></button>
        </div>)}</div>
      </section>

      <section className="list-card website-settings-section">
        <header className="website-editor-section-heading"><div><h3>Photo gallery</h3><p>Demo artwork is local and can be replaced with your school’s own images.</p></div><button className="secondary-button" type="button" onClick={addGalleryItem}><Plus size={15} />Add photo</button></header>
        <div className="website-editor-cards">{content.gallery.map((item, index) => {
          const previewUrl = galleryPreviews[item.id] ?? item.image_url;
          return <article className="website-editor-card website-gallery-editor-card" key={item.id}>
            <div className="website-gallery-preview">{previewUrl && <Image src={previewUrl} alt="" width={260} height={140} unoptimized />}</div>
            <div className="website-gallery-fields">
              <label>Title<input maxLength={255} value={item.title} onChange={(event) => updateGallery(item.id, { title: event.target.value })} /></label>
              <label>Description<textarea rows={2} maxLength={2000} value={item.description} onChange={(event) => updateGallery(item.id, { description: event.target.value })} /></label>
              <label>Replace image<input type="file" accept="image/png,image/jpeg,image/webp" onChange={(event) => changeGalleryImage(event, item.id)} /></label>
              {galleryFiles[item.id] && <small>{galleryFiles[item.id].name}</small>}
              {fieldErrors[`content.gallery.${index}.image`]?.[0] && <small className="field-error">{fieldErrors[`content.gallery.${index}.image`][0]}</small>}
              <label>Publishing status<select value={item.status} onChange={(event) => updateGallery(item.id, { status: event.target.value as WebsiteGalleryItem["status"] })}><option value="draft">Draft</option><option value="published">Published</option></select></label>
              <label className="website-inline-checkbox"><input type="checkbox" checked={item.is_active} onChange={(event) => updateGallery(item.id, { is_active: event.target.checked })} />Active</label>
              <button className="danger-button website-remove-button" type="button" onClick={() => removeGalleryItem(item.id)}><Trash2 size={15} />Remove photo</button>
              {!item.image_url && !galleryFiles[item.id] && <small className="field-error">Upload an image before saving this new item.</small>}
            </div>
          </article>;
        })}</div>
      </section>

      <WebsiteEntryEditor title="Notice board" addLabel="Add notice" items={content.notices} onAdd={() => addEntry("notices")} onChange={(id, changes) => updateEntry("notices", id, changes)} onRemove={(id) => update("notices", content.notices.filter((item) => item.id !== id))} />
      <WebsiteEntryEditor title="News and events" addLabel="Add news or event" items={content.news} onAdd={() => addEntry("news")} onChange={(id, changes) => updateEntry("news", id, changes)} onRemove={(id) => update("news", content.news.filter((item) => item.id !== id))} />

      <section className="list-card website-settings-section">
        <header className="website-editor-section-heading"><div><h3>Website-only staff profiles</h3><p>These profiles are website content only; no teacher accounts or academic records are changed.</p></div><button className="secondary-button" type="button" onClick={() => update("staff", [...content.staff, { id: newId("staff"), name: "", designation: "", bio: "", image_url: null, status: "draft" }])}><Plus size={15} />Add profile</button></header>
        <div className="website-editor-cards">{content.staff.map((member) => <article className="website-editor-card website-staff-editor-card" key={member.id}>
          {member.image_url && <Image src={member.image_url} alt="" width={84} height={84} unoptimized />}
          <div className="website-gallery-fields">
            <label>Name<input maxLength={255} value={member.name} onChange={(event) => updateStaff(member.id, { name: event.target.value })} /></label>
            <label>Designation<input maxLength={255} value={member.designation} onChange={(event) => updateStaff(member.id, { designation: event.target.value })} /></label>
            <label>Bio<textarea rows={2} maxLength={1000} value={member.bio} onChange={(event) => updateStaff(member.id, { bio: event.target.value })} /></label>
            <label>Publishing status<select value={member.status ?? "published"} onChange={(event) => updateStaff(member.id, { status: event.target.value as WebsiteStaffProfile["status"] })}><option value="draft">Draft</option><option value="published">Published</option></select></label>
            <label>Replace profile photo<input type="file" accept="image/png,image/jpeg,image/webp" onChange={(event) => onProfileFile(member.id, event.target.files?.[0] ?? null)} /></label>
            {profileFiles[member.id] && <small>{profileFiles[member.id].name}</small>}
            <button className="danger-button website-remove-button" type="button" onClick={() => update("staff", content.staff.filter((profile) => profile.id !== member.id))}><Trash2 size={15} />Remove profile</button>
          </div>
        </article>)}</div>
      </section>
    </div>
  );
}

function WebsiteEntryEditor({ title, addLabel, items, onAdd, onChange, onRemove }: {
  title: string;
  addLabel: string;
  items: WebsiteContentEntry[];
  onAdd: () => void;
  onChange: (id: string, changes: Partial<WebsiteContentEntry>) => void;
  onRemove: (id: string) => void;
}) {
  return <section className="list-card website-settings-section">
    <header className="website-editor-section-heading"><div><h3>{title}</h3><p>Demo entries are labeled on the public site; set status to Draft to hide an entry.</p></div><button className="secondary-button" type="button" onClick={onAdd}><Plus size={15} />{addLabel}</button></header>
    <div className="website-editor-cards">{items.map((item) => <article className="website-editor-card" key={item.id}>
      <label>Title<input maxLength={255} value={item.title} onChange={(event) => onChange(item.id, { title: event.target.value })} /></label>
      <label>Description<textarea rows={3} maxLength={3000} value={item.description} onChange={(event) => onChange(item.id, { description: event.target.value })} /></label>
      <label>Date<input type="date" value={item.date ?? ""} onChange={(event) => onChange(item.id, { date: event.target.value })} /></label>
      <label>Publishing status<select value={item.status} onChange={(event) => onChange(item.id, { status: event.target.value as WebsiteContentEntry["status"] })}><option value="draft">Draft</option><option value="published">Published</option></select></label>
      <label className="website-inline-checkbox"><input type="checkbox" checked={item.is_demo} onChange={(event) => onChange(item.id, { is_demo: event.target.checked })} />Mark as demo content</label>
      <button className="danger-button website-remove-button" type="button" onClick={() => onRemove(item.id)}><Trash2 size={15} />Remove</button>
    </article>)}</div>
  </section>;
}

function EditableList({ title, items, onChange }: { title: string; items: string[]; onChange: (items: string[]) => void }) {
  return <fieldset className="website-editable-list"><legend>{title}</legend>{items.map((item, index) => <div className="website-editor-row" key={`${title}-${index}`}>
    <input aria-label={`${title} item ${index + 1}`} maxLength={500} value={item} onChange={(event) => onChange(items.map((value, itemIndex) => itemIndex === index ? event.target.value : value))} />
    <button className="danger-button website-remove-button" type="button" aria-label={`Remove ${title.toLowerCase()} item`} onClick={() => onChange(items.filter((_, itemIndex) => itemIndex !== index))}><Trash2 size={15} /></button>
  </div>)}<button className="secondary-button" type="button" onClick={() => onChange([...items, ""])}><Plus size={15} />Add item</button></fieldset>;
}

function TextArea({ label, value, onChange }: { label: string; value: string; onChange: (value: string) => void }) {
  return <label>{label}<textarea rows={4} maxLength={10000} value={value} onChange={(event) => onChange(event.target.value)} /></label>;
}
