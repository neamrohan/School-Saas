"use client";

import Image from "next/image";
import { usePathname } from "next/navigation";
import { useCallback, useEffect, useRef, useState, type ChangeEvent, type FormEvent } from "react";
import { ArrowDown, ArrowUp, Eye, ImagePlus, LoaderCircle, Pencil, Plus, Save, Trash2, X } from "lucide-react";
import { ErrorState, LoadingState, Notice } from "@/components/admin/AdminStates";
import { api, ApiRequestError } from "@/lib/api";
import type { HomepageSlider } from "@/types";

type SliderValues = {
  title: string;
  subtitle: string;
  description: string;
  button_text: string;
  button_url: string;
  display_order: string;
  is_active: boolean;
  status: HomepageSlider["status"];
};

const emptyValues: SliderValues = {
  title: "",
  subtitle: "",
  description: "",
  button_text: "",
  button_url: "",
  display_order: "0",
  is_active: true,
  status: "draft",
};

export default function HomepageSlidersPage() {
  const pathname = usePathname();
  const superAdminSchoolId = pathname.match(/^\/super-admin\/schools\/(\d+)\/website-management\/sliders$/)?.[1];
  const sliderEndpoint = superAdminSchoolId ? `/schools/${superAdminSchoolId}/sliders` : "/school/sliders";
  const [sliders, setSliders] = useState<HomepageSlider[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState("");
  const [saving, setSaving] = useState(false);
  const [reordering, setReordering] = useState(false);
  const [editing, setEditing] = useState<HomepageSlider | null>(null);
  const [formOpen, setFormOpen] = useState(false);
  const [preview, setPreview] = useState<HomepageSlider | null>(null);
  const [values, setValues] = useState<SliderValues>(emptyValues);
  const [image, setImage] = useState<File | null>(null);
  const [mobileImage, setMobileImage] = useState<File | null>(null);
  const [imagePreview, selectImagePreview] = useFilePreview(null);
  const [mobilePreview, selectMobilePreview] = useFilePreview(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [notice, setNotice] = useState("");
  const [noticeTone, setNoticeTone] = useState<"success" | "error">("success");
  const [busyId, setBusyId] = useState<number | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setLoadError("");
    try {
      const result = await api.get<{ sliders: HomepageSlider[] }>(sliderEndpoint);
      setSliders(result.sliders);
    } catch (cause) {
      setLoadError(cause instanceof ApiRequestError ? cause.message : "Unable to load homepage sliders.");
    } finally {
      setLoading(false);
    }
  }, [sliderEndpoint]);

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  function openCreate() {
    setEditing(null);
    setFormOpen(true);
    setValues(emptyValues);
    setImage(null);
    setMobileImage(null);
    selectImagePreview(null);
    selectMobilePreview(null);
    setFieldErrors({});
    setNotice("");
  }

  async function openEdit(id: number) {
    setBusyId(id);
    setNotice("");
    try {
      const result = await api.get<{ slider: HomepageSlider }>(`${sliderEndpoint}/${id}`);
      const slider = result.slider;
      setEditing(slider);
      setFormOpen(true);
      setValues({
        title: slider.title,
        subtitle: slider.subtitle ?? "",
        description: slider.description ?? "",
        button_text: slider.button_text ?? "",
        button_url: slider.button_url ?? "",
        display_order: String(slider.display_order),
        is_active: slider.is_active,
        status: slider.status,
      });
      setImage(null);
      setMobileImage(null);
      selectImagePreview(null);
      selectMobilePreview(null);
      setFieldErrors({});
    } catch (cause) {
      showError(cause, "Unable to load this slider.");
    } finally {
      setBusyId(null);
    }
  }

  function showError(cause: unknown, fallback: string) {
    setNotice(cause instanceof ApiRequestError ? cause.message : fallback);
    setNoticeTone("error");
  }

  function changeImage(event: ChangeEvent<HTMLInputElement>, mobile = false) {
    const file = event.target.files?.[0] ?? null;
    if (mobile) {
      setMobileImage(file);
      selectMobilePreview(file);
      setFieldErrors((current) => ({ ...current, mobile_image: [] }));
    } else {
      setImage(file);
      selectImagePreview(file);
      setFieldErrors((current) => ({ ...current, image: [] }));
    }
    setNotice("");
  }

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!editing && !image) {
      setFieldErrors((current) => ({ ...current, image: ["Please choose a desktop image."] }));
      return;
    }

    setSaving(true);
    setNotice("");
    setFieldErrors({});
    const form = new FormData();
    form.append("_method", editing ? "PUT" : "POST");
    Object.entries(values).forEach(([key, value]) => form.append(key, typeof value === "boolean" ? (value ? "1" : "0") : value));
    if (image) form.append("image", image);
    if (mobileImage) form.append("mobile_image", mobileImage);

    try {
      const result = await api.postForm<{ message: string; slider: HomepageSlider }>(
        editing ? `${sliderEndpoint}/${editing.id}` : sliderEndpoint,
        form,
      );
      setSliders((current) => {
        const next = current.filter((slider) => slider.id !== result.slider.id);
        next.push(result.slider);
        return next.sort((a, b) => a.display_order - b.display_order || a.id - b.id);
      });
      setEditing(null);
      setFormOpen(false);
      setNotice(result.message);
      setNoticeTone("success");
    } catch (cause) {
      setFieldErrors(cause instanceof ApiRequestError ? cause.details?.errors ?? {} : {});
      showError(cause, "Unable to save this slider. Please try again.");
    } finally {
      setSaving(false);
    }
  }

  async function removeSlider(slider: HomepageSlider) {
    if (!window.confirm(`Delete the slider "${slider.title}"? This cannot be undone.`)) return;
    setBusyId(slider.id);
    setNotice("");
    try {
      const result = await api.delete<{ message: string }>(`${sliderEndpoint}/${slider.id}`);
      setSliders((current) => current.filter((item) => item.id !== slider.id));
      setNotice(result.message);
      setNoticeTone("success");
    } catch (cause) {
      showError(cause, "Unable to delete this slider.");
    } finally {
      setBusyId(null);
    }
  }

  async function moveSlider(index: number, direction: -1 | 1) {
    const targetIndex = index + direction;
    if (targetIndex < 0 || targetIndex >= sliders.length || reordering) return;
    const next = [...sliders];
    [next[index], next[targetIndex]] = [next[targetIndex], next[index]];
    setReordering(true);
    setNotice("");
    try {
      const result = await api.put<{ message: string; sliders: HomepageSlider[] }>(`${sliderEndpoint}/reorder`, {
        slider_ids: next.map((slider) => slider.id),
      });
      setSliders(result.sliders);
      setNotice(result.message);
      setNoticeTone("success");
    } catch (cause) {
      showError(cause, "Unable to save the new slider order.");
    } finally {
      setReordering(false);
    }
  }

  if (loading) return <LoadingState label="Loading homepage sliders..." />;
  if (loadError) return <ErrorState message={loadError} onRetry={() => void load()} />;

  const activePreview = imagePreview ?? editing?.image_url ?? null;
  const mobilePreviewUrl = mobilePreview ?? editing?.mobile_image_url ?? null;

  return (
    <>
      <section className="admin-page-heading">
        <div><p className="kicker">Website Management</p><h2>Homepage Slider</h2><p>Manage the slides displayed on your public school website.</p></div>
        <button className="primary-button compact-button" type="button" onClick={openCreate}><Plus size={16} />Add New Slider</button>
      </section>
      {notice && <Notice message={notice} tone={noticeTone} />}
      {sliders.length === 0 ? (
        <section className="list-card slider-empty"><ImagePlus size={32} /><h3>No homepage slides yet</h3><p>Add a slide to feature important information on your school website.</p><button className="primary-button compact-button" type="button" onClick={openCreate}><Plus size={16} />Add New Slider</button></section>
      ) : (
        <section className="list-card slider-table-card">
          <div className="slider-table-scroll"><table className="data-table slider-table">
            <thead><tr><th>Image</th><th>Title</th><th>Order</th><th>Active</th><th>Publishing</th><th>Actions</th></tr></thead>
            <tbody>{sliders.map((slider, index) => (
              <tr key={slider.id}>
                <td><Image className="slider-thumbnail" src={slider.image_url} alt="" width={88} height={56} unoptimized /></td>
                <td><strong>{slider.title}</strong>{slider.subtitle && <small>{slider.subtitle}</small>}</td>
                <td><div className="slider-order-actions"><span>{slider.display_order}</span><button type="button" aria-label={`Move ${slider.title} up`} disabled={index === 0 || reordering} onClick={() => void moveSlider(index, -1)}><ArrowUp size={15} /></button><button type="button" aria-label={`Move ${slider.title} down`} disabled={index === sliders.length - 1 || reordering} onClick={() => void moveSlider(index, 1)}><ArrowDown size={15} /></button></div></td>
                <td><span className={`slider-status ${slider.is_active ? "slider-status-active" : "slider-status-inactive"}`}>{slider.is_active ? "Active" : "Inactive"}</span></td>
                <td><span className={`slider-status ${slider.status === "published" ? "slider-status-active" : "slider-status-draft"}`}>{slider.status === "published" ? "Published" : "Draft"}</span></td>
                <td><div className="row-actions slider-row-actions">
                  <button type="button" aria-label={`Preview ${slider.title}`} title="Preview" onClick={() => setPreview(slider)}><Eye size={16} /></button>
                  <button type="button" aria-label={`Edit ${slider.title}`} title="Edit" disabled={busyId === slider.id} onClick={() => void openEdit(slider.id)}><Pencil size={16} /></button>
                  <button className="row-danger" type="button" aria-label={`Delete ${slider.title}`} title="Delete" disabled={busyId === slider.id} onClick={() => void removeSlider(slider)}><Trash2 size={16} /></button>
                </div></td>
              </tr>
            ))}</tbody>
          </table></div>
        </section>
      )}

      {formOpen && (
        <SliderFormDialog
          title={editing ? "Edit homepage slider" : "Add homepage slider"}
          values={values}
          imagePreview={activePreview}
          mobilePreview={mobilePreviewUrl}
          imageError={fieldErrors.image?.[0]}
          mobileError={fieldErrors.mobile_image?.[0]}
          fieldErrors={fieldErrors}
          saving={saving}
          onClose={() => { setEditing(null); setFormOpen(false); }}
          onChange={(name, value) => setValues((current) => ({ ...current, [name]: value }))}
          onImageChange={changeImage}
          onSubmit={save}
        />
      )}
      {preview && <SliderPreviewDialog slider={preview} onClose={() => setPreview(null)} />}
    </>
  );
}

function SliderFormDialog({
  title, values, imagePreview, mobilePreview, imageError, mobileError, fieldErrors, saving, onClose, onChange, onImageChange, onSubmit,
}: {
  title: string;
  values: SliderValues;
  imagePreview: string | null;
  mobilePreview: string | null;
  imageError?: string;
  mobileError?: string;
  fieldErrors: Record<string, string[]>;
  saving: boolean;
  onClose: () => void;
  onChange: <K extends keyof SliderValues>(name: K, value: SliderValues[K]) => void;
  onImageChange: (event: ChangeEvent<HTMLInputElement>, mobile?: boolean) => void;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}) {
  return (
    <div className="slider-modal-backdrop">
      <section className="slider-dialog" role="dialog" aria-modal="true" aria-labelledby="slider-dialog-title">
        <header><div><p className="kicker">Website Management</p><h2 id="slider-dialog-title">{title}</h2></div><button className="icon-button" type="button" onClick={onClose} aria-label="Close form"><X size={19} /></button></header>
        <form className="school-form slider-form" onSubmit={onSubmit}>
          <div className="form-grid">
            <label>Title *<input required maxLength={255} value={values.title} onChange={(event) => onChange("title", event.target.value)} />{fieldErrors.title?.[0] && <small className="field-error">{fieldErrors.title[0]}</small>}</label>
            <label>Subtitle<input maxLength={255} value={values.subtitle} onChange={(event) => onChange("subtitle", event.target.value)} />{fieldErrors.subtitle?.[0] && <small className="field-error">{fieldErrors.subtitle[0]}</small>}</label>
            <label className="wide-field">Description<textarea rows={4} maxLength={10000} value={values.description} onChange={(event) => onChange("description", event.target.value)} />{fieldErrors.description?.[0] && <small className="field-error">{fieldErrors.description[0]}</small>}</label>
            <ImageField label="Desktop image *" preview={imagePreview} error={imageError} required={!imagePreview} onChange={(event) => onImageChange(event)} />
            <ImageField label="Mobile image (optional)" preview={mobilePreview} error={mobileError} onChange={(event) => onImageChange(event, true)} />
            <label>Button text<input maxLength={100} value={values.button_text} onChange={(event) => onChange("button_text", event.target.value)} />{fieldErrors.button_text?.[0] && <small className="field-error">{fieldErrors.button_text[0]}</small>}</label>
            <label>Button URL<input type="text" maxLength={2048} value={values.button_url} onChange={(event) => onChange("button_url", event.target.value)} placeholder="https://example.com or #about" />{fieldErrors.button_url?.[0] && <small className="field-error">{fieldErrors.button_url[0]}</small>}</label>
            <label>Display order<input type="number" min={0} max={4294967295} step={1} value={values.display_order} onChange={(event) => onChange("display_order", event.target.value)} />{fieldErrors.display_order?.[0] && <small className="field-error">{fieldErrors.display_order[0]}</small>}</label>
            <label>Publishing status<select value={values.status} onChange={(event) => onChange("status", event.target.value as HomepageSlider["status"])}><option value="draft">Draft</option><option value="published">Published</option></select>{fieldErrors.status?.[0] && <small className="field-error">{fieldErrors.status[0]}</small>}</label>
            <label className="slider-checkbox-field"><input type="checkbox" checked={values.is_active} onChange={(event) => onChange("is_active", event.target.checked)} />Active</label>
          </div>
          <footer><button className="secondary-button" type="button" disabled={saving} onClick={onClose}>Cancel</button><button className="primary-button compact-button" type="submit" disabled={saving}>{saving ? <LoaderCircle className="loading-icon" size={16} /> : <Save size={16} />}{saving ? "Saving..." : "Save slider"}</button></footer>
        </form>
      </section>
    </div>
  );
}

function ImageField({ label, preview, error, required, onChange }: {
  label: string;
  preview: string | null;
  error?: string;
  required?: boolean;
  onChange: (event: ChangeEvent<HTMLInputElement>) => void;
}) {
  return <label className="slider-image-field">{label}<span className="slider-image-preview">{preview ? <Image src={preview} alt={`${label} preview`} width={320} height={140} unoptimized /> : <ImagePlus size={24} />}</span><input type="file" accept="image/png,image/jpeg,image/webp" required={required} onChange={onChange} />{error && <small className="field-error">{error}</small>}</label>;
}

function SliderPreviewDialog({ slider, onClose }: { slider: HomepageSlider; onClose: () => void }) {
  return <div className="slider-modal-backdrop"><section className="slider-preview-dialog" role="dialog" aria-modal="true" aria-labelledby="slider-preview-title">
    <button className="icon-button slider-preview-close" type="button" onClick={onClose} aria-label="Close preview"><X size={19} /></button>
    <div className="slider-preview-image"><Image src={slider.image_url} alt={slider.title} fill sizes="(max-width: 700px) 100vw, 720px" unoptimized /></div>
    <div className="slider-preview-content"><p className="kicker">{slider.status === "published" ? "Published" : "Draft"} · {slider.is_active ? "Active" : "Inactive"}</p><h2 id="slider-preview-title">{slider.title}</h2>{slider.subtitle && <h3>{slider.subtitle}</h3>}{slider.description && <p>{slider.description}</p>}{slider.button_text && <span className="primary-button compact-button">{slider.button_text}</span>}</div>
  </section></div>;
}

function useFilePreview(fallback: string | null): [string | null, (file: File | null) => void] {
  const [preview, setPreview] = useState<string | null>(null);
  const objectUrl = useRef<string | null>(null);
  useEffect(() => () => { if (objectUrl.current) URL.revokeObjectURL(objectUrl.current); }, []);
  const selectFile = useCallback((file: File | null) => {
    if (objectUrl.current) URL.revokeObjectURL(objectUrl.current);
    objectUrl.current = file ? URL.createObjectURL(file) : null;
    setPreview(objectUrl.current);
  }, []);
  return [preview ?? fallback, selectFile];
}
