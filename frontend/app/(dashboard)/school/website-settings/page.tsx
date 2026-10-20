"use client";

import { useCallback, useEffect, useRef, useState, type ChangeEvent, type FormEvent } from "react";
import Image from "next/image";
import { usePathname } from "next/navigation";
import { Globe2, ImagePlus, LoaderCircle, Save } from "lucide-react";
import { api, ApiRequestError } from "@/lib/api";
import { ErrorState, LoadingState, Notice } from "@/components/admin/AdminStates";
import { WebsiteContentEditor } from "./WebsiteContentEditor";
import type { SchoolWebsiteSettings, WebsiteContent } from "@/types";

type TextField = "website_name" | "meta_description" | "tagline" | "contact_email" | "phone" | "address" | "about" | "facebook_url" | "instagram_url" | "youtube_url" | "footer_text";
type WebsiteFormValues = Pick<SchoolWebsiteSettings, TextField | "primary_color" | "secondary_color" | "font_family">;

const textFields: Array<{ name: TextField; label: string; type?: string; multiline?: boolean }> = [
  { name: "website_name", label: "Website display name" },
  { name: "meta_description", label: "Search description", multiline: true },
  { name: "tagline", label: "Tagline" },
  { name: "contact_email", label: "Contact email", type: "email" },
  { name: "phone", label: "Phone number", type: "tel" },
  { name: "address", label: "Address", multiline: true },
  { name: "about", label: "About your school", multiline: true },
  { name: "facebook_url", label: "Facebook URL", type: "url" },
  { name: "instagram_url", label: "Instagram URL", type: "url" },
  { name: "youtube_url", label: "YouTube URL", type: "url" },
  { name: "footer_text", label: "Website footer text", multiline: true },
];

export default function WebsiteSettingsPage() {
  const pathname = usePathname();
  const superAdminSchoolId = pathname.match(/^\/super-admin\/schools\/(\d+)\/website-settings$/)?.[1];
  const settingsEndpoint = superAdminSchoolId
    ? `/schools/${superAdminSchoolId}/website-settings`
    : "/school/website-settings";
  const [settings, setSettings] = useState<SchoolWebsiteSettings | null>(null);
  const [values, setValues] = useState<WebsiteFormValues | null>(null);
  const [content, setContent] = useState<WebsiteContent | null>(null);
  const [galleryFiles, setGalleryFiles] = useState<Record<string, File>>({});
  const [profileFiles, setProfileFiles] = useState<Record<string, File>>({});
  const [logo, setLogo] = useState<File | null>(null);
  const [favicon, setFavicon] = useState<File | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [noticeTone, setNoticeTone] = useState<"success" | "error">("success");
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [logoPreview, selectLogoPreview] = usePreview(settings?.logo_url ?? null);
  const [faviconPreview, selectFaviconPreview] = usePreview(settings?.favicon_url ?? null);
  const hasUnsavedChanges = Boolean(
    settings && values && (
      JSON.stringify(values) !== JSON.stringify(toFormValues(settings))
      || JSON.stringify(content) !== JSON.stringify(settings.content)
      || logo
      || favicon
      || Object.keys(galleryFiles).length
      || Object.keys(profileFiles).length
    ),
  );

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const result = await api.get<{ settings: SchoolWebsiteSettings }>(settingsEndpoint);
      setSettings(result.settings);
      setValues(toFormValues(result.settings));
      setContent(result.settings.content);
      setGalleryFiles({});
      setProfileFiles({});
    } catch (cause) {
      setError(cause instanceof ApiRequestError ? cause.message : "Unable to load website settings.");
    } finally {
      setLoading(false);
    }
  }, [settingsEndpoint]);

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  useEffect(() => {
    if (!hasUnsavedChanges) return;
    function preventUnsavedExit(event: BeforeUnloadEvent) {
      event.preventDefault();
      event.returnValue = "";
    }
    window.addEventListener("beforeunload", preventUnsavedExit);
    return () => window.removeEventListener("beforeunload", preventUnsavedExit);
  }, [hasUnsavedChanges]);

  function changeText(name: TextField, value: string) {
    setValues((current) => current ? { ...current, [name]: value } : current);
    setFieldErrors((current) => ({ ...current, [name]: [] }));
    setNotice("");
  }

  function changeFile(event: ChangeEvent<HTMLInputElement>, kind: "logo" | "favicon") {
    const file = event.target.files?.[0] ?? null;
    if (kind === "logo") {
      setLogo(file);
      selectLogoPreview(file);
    } else {
      setFavicon(file);
      selectFaviconPreview(file);
    }
    setFieldErrors((current) => ({ ...current, [kind]: [] }));
    setNotice("");
  }

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!values || !settings) return;
    setSaving(true);
    setNotice("");
    setFieldErrors({});

    const formData = new FormData();
    for (const [name, value] of Object.entries(values)) {
      formData.append(name, value ?? "");
    }
    if (content) {
      formData.append("content", JSON.stringify(content));
      content.gallery.forEach((item, index) => {
        const file = galleryFiles[item.id];
        if (file) formData.append(`gallery_images[${index}]`, file);
      });
      if (profileFiles.principal) formData.append("principal_image", profileFiles.principal);
      content.staff.forEach((member, index) => {
        const file = profileFiles[member.id];
        if (file) formData.append(`staff_images[${index}]`, file);
      });
    }
    if (logo) formData.append("logo", logo);
    if (favicon) formData.append("favicon", favicon);

    try {
      const result = await api.postForm<{ message: string; settings: SchoolWebsiteSettings }>(settingsEndpoint, formData);
      setSettings(result.settings);
      setValues(toFormValues(result.settings));
      setContent(result.settings.content);
      setGalleryFiles({});
      setProfileFiles({});
      setLogo(null);
      setFavicon(null);
      selectLogoPreview(null);
      selectFaviconPreview(null);
      setNotice(result.message);
      setNoticeTone("success");
    } catch (cause) {
      if (cause instanceof ApiRequestError) {
        setFieldErrors(cause.details?.errors ?? {});
        setNotice(cause.message);
      } else {
        setNotice("Unable to save website settings. Please try again.");
      }
      setNoticeTone("error");
    } finally {
      setSaving(false);
    }
  }

  if (loading) return <LoadingState label="Loading website settings..." />;
  if (error) return <ErrorState message={error} onRetry={() => void load()} />;
  if (!settings || !values) return null;

  return (
    <>
      <section className="admin-page-heading">
        <div><p className="kicker">School website</p><h2>Website Settings</h2><p>{superAdminSchoolId ? "Manage this school's public website." : "Customize the public website for your school."}</p></div>
        <a className="secondary-button" href={`/?school=${encodeURIComponent(settings.school_code)}`} target="_blank" rel="noreferrer"><Globe2 size={16} />Preview website</a>
      </section>
      {notice && <Notice message={notice} tone={noticeTone} />}
      {hasUnsavedChanges && <p className="unsaved-changes-notice" role="status">You have unsaved website changes.</p>}
      <form className="school-form website-settings-form" onSubmit={save}>
        <section className="list-card website-settings-section">
          <h3>School identity and metadata</h3>
          <div className="form-grid">
            {textFields.slice(0, 3).map((field) => <TextInput key={field.name} {...field} value={values[field.name] ?? ""} error={fieldErrors[field.name]?.[0]} onChange={changeText} required={field.name === "website_name"} />)}
            <FilePreview label="School logo" accept="image/png,image/jpeg,image/webp" file={logo} preview={logoPreview} error={fieldErrors.logo?.[0]} onChange={(event) => changeFile(event, "logo")} />
            <FilePreview label="Favicon" accept=".ico,image/png,image/jpeg,image/webp" file={favicon} preview={faviconPreview} error={fieldErrors.favicon?.[0]} onChange={(event) => changeFile(event, "favicon")} />
          </div>
        </section>
        <section className="list-card website-settings-section">
          <h3>Contact and about</h3>
          <div className="form-grid">
            {textFields.slice(3, 7).map((field) => <TextInput key={field.name} {...field} value={values[field.name] ?? ""} error={fieldErrors[field.name]?.[0]} onChange={changeText} />)}
          </div>
        </section>
        <section className="list-card website-settings-section">
          <h3>Brand and social links</h3>
          <div className="form-grid">
            <label>Primary color<input type="color" value={values.primary_color} onChange={(event) => setValues((current) => current ? { ...current, primary_color: event.target.value } : current)} />{fieldErrors.primary_color?.[0] && <small className="field-error">{fieldErrors.primary_color[0]}</small>}</label>
            <label>Secondary color<input type="color" value={values.secondary_color} onChange={(event) => setValues((current) => current ? { ...current, secondary_color: event.target.value } : current)} />{fieldErrors.secondary_color?.[0] && <small className="field-error">{fieldErrors.secondary_color[0]}</small>}</label>
            <label>Website font<select value={values.font_family} onChange={(event) => setValues((current) => current ? { ...current, font_family: event.target.value as SchoolWebsiteSettings["font_family"] } : current)}><option value="system">System sans-serif</option><option value="sans-serif">Sans-serif</option><option value="serif">Serif</option></select></label>
            {textFields.slice(7).map((field) => <TextInput key={field.name} {...field} value={values[field.name] ?? ""} error={fieldErrors[field.name]?.[0]} onChange={changeText} />)}
          </div>
        </section>
        {content ? (
          <WebsiteContentEditor
            content={content}
            galleryFiles={galleryFiles}
            profileFiles={profileFiles}
            fieldErrors={fieldErrors}
            onChange={setContent}
            onGalleryFile={(id, file) => setGalleryFiles((current) => {
              const next = { ...current };
              if (file) next[id] = file;
              else delete next[id];
              return next;
            })}
            onProfileFile={(id, file) => setProfileFiles((current) => {
              const next = { ...current };
              if (file) next[id] = file;
              else delete next[id];
              return next;
            })}
          />
        ) : (
          <section className="list-card website-settings-section">
            <h3>Homepage content template</h3>
            <p>This school’s website content has not been initialized. A Super Admin can initialize the demo template for this school with <code>php artisan school:initialize-demo-website {settings.school_code}</code>. Existing website settings will not be overwritten.</p>
          </section>
        )}
        <div className="website-settings-actions"><button className="primary-button compact-button" type="submit" disabled={saving}>{saving ? <LoaderCircle className="loading-icon" size={17} /> : <Save size={17} />}{saving ? "Saving settings..." : "Save settings"}</button></div>
      </form>
    </>
  );
}

function TextInput({ name, label, type = "text", multiline, value, error, required, onChange }: {
  name: TextField;
  label: string;
  type?: string;
  multiline?: boolean;
  value: string;
  error?: string;
  required?: boolean;
  onChange: (name: TextField, value: string) => void;
}) {
  return   <label className={multiline ? "wide-field" : undefined}>{label}{multiline ? <textarea rows={name === "about" ? 5 : 3} value={value} maxLength={name === "about" ? 10000 : name === "meta_description" ? 320 : 2000} onChange={(event) => onChange(name, event.target.value)} required={required} /> : <input type={type} value={value} maxLength={name === "website_name" || name === "tagline" ? 255 : undefined} onChange={(event) => onChange(name, event.target.value)} required={required} />}{error && <small className="field-error">{error}</small>}</label>;
}

function FilePreview({ label, accept, preview, file, error, onChange }: {
  label: string;
  accept: string;
  preview: string | null;
  file: File | null;
  error?: string;
  onChange: (event: ChangeEvent<HTMLInputElement>) => void;
}) {
  return <label className="website-file-field">{label}<span className="website-image-preview">{preview ? <Image src={preview} alt={`${label} preview`} width={180} height={100} unoptimized /> : <ImagePlus size={23} />}</span><input key={file?.name ?? "no-file"} type="file" accept={accept} onChange={onChange} />{file && <small>{file.name}</small>}{error && <small className="field-error">{error}</small>}</label>;
}

function usePreview(fallback: string | null): [string | null, (file: File | null) => void] {
  const [preview, setPreview] = useState<string | null>(null);
  const objectUrl = useRef<string | null>(null);

  useEffect(() => {
    return () => {
      if (objectUrl.current) URL.revokeObjectURL(objectUrl.current);
    };
  }, []);

  const selectFile = useCallback((file: File | null) => {
    if (objectUrl.current) URL.revokeObjectURL(objectUrl.current);
    objectUrl.current = file ? URL.createObjectURL(file) : null;
    setPreview(objectUrl.current);
  }, []);

  return [preview ?? fallback, selectFile];
}

function toFormValues(settings: SchoolWebsiteSettings): WebsiteFormValues {
  return {
    website_name: settings.website_name,
    meta_description: settings.meta_description ?? "",
    tagline: settings.tagline ?? "",
    contact_email: settings.contact_email ?? "",
    phone: settings.phone ?? "",
    address: settings.address ?? "",
    about: settings.about ?? "",
    primary_color: settings.primary_color,
    secondary_color: settings.secondary_color,
    facebook_url: settings.facebook_url ?? "",
    instagram_url: settings.instagram_url ?? "",
    youtube_url: settings.youtube_url ?? "",
    footer_text: settings.footer_text ?? "",
    font_family: settings.font_family ?? "system",
  };
}
