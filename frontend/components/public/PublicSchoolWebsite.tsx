"use client";

import Image from "next/image";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useRef, useState, type CSSProperties, type FormEvent } from "react";
import { AtSign, ChevronLeft, ChevronRight, Globe2, Mail, MapPin, Phone, PlayCircle } from "lucide-react";
import { api, ApiRequestError } from "@/lib/api";
import { LoadingState } from "@/components/admin/AdminStates";
import type { HomepageSlider, PublicSchoolOption, SchoolWebsiteSettings, WebsiteContentEntry, WebsiteSectionKey } from "@/types";

const defaultNavigation = [
  { id: "nav-about", label: "About", href: "#about" },
  { id: "nav-gallery", label: "Gallery", href: "#gallery" },
  { id: "nav-notices", label: "Notices", href: "#notices" },
  { id: "nav-contact", label: "Contact", href: "#contact" },
];

export default function PublicSchoolWebsite({ initialSchoolCode }: { initialSchoolCode?: string }) {
  const router = useRouter();
  const [schoolCode, setSchoolCode] = useState(initialSchoolCode ?? "");
  const [settings, setSettings] = useState<SchoolWebsiteSettings | null>(null);
  const [sliders, setSliders] = useState<HomepageSlider[]>([]);
  const [schoolOptions, setSchoolOptions] = useState<PublicSchoolOption[]>([]);
  const [sliderError, setSliderError] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [contactStatus, setContactStatus] = useState("");
  const [contactError, setContactError] = useState("");
  const [contactSending, setContactSending] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const result = await api.get<{ settings: SchoolWebsiteSettings }>(`/public/schools/${encodeURIComponent(schoolCode)}/website`);
      setSettings(result.settings);
      const schoolsResult = await api.get<{ schools: PublicSchoolOption[] }>("/public/schools");
      setSchoolOptions(schoolsResult.schools);
      try {
        const sliderResult = await api.get<{ sliders: HomepageSlider[] }>(`/public/schools/${encodeURIComponent(schoolCode)}/sliders`);
        setSliders(sliderResult.sliders);
        setSliderError(false);
      } catch {
        setSliders([]);
        setSliderError(true);
      }
    } catch (cause) {
      setError(cause instanceof ApiRequestError ? cause.message : "Unable to load this school website.");
    } finally {
      setLoading(false);
    }
  }, [schoolCode]);

  useEffect(() => {
    if (schoolCode) return;
    const timer = window.setTimeout(() => {
      void api.get<{ school_code: string }>("/public/default-school")
        .then(({ school_code }) => setSchoolCode(school_code))
        .catch((cause: unknown) => {
          setError(cause instanceof ApiRequestError ? cause.message : "Unable to load the default school website.");
          setLoading(false);
        });
    }, 0);
    return () => window.clearTimeout(timer);
  }, [schoolCode]);

  useEffect(() => {
    if (!schoolCode) return;
    const timer = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timer);
  }, [load, schoolCode]);

  useEffect(() => {
    if (!settings) return;
    document.title = settings.website_name;
    let description = document.querySelector<HTMLMetaElement>('meta[name="description"]');
    if (!description) {
      description = document.createElement("meta");
      description.name = "description";
      document.head.append(description);
    }
    description.content = settings.meta_description || settings.about || settings.tagline || settings.website_name;
    const favicon = document.querySelector<HTMLLinkElement>("link[data-school-favicon]");
    if (!settings.favicon_url) {
      favicon?.remove();
      return;
    }

    const link = favicon ?? document.createElement("link");
    link.rel = "icon";
    link.href = settings.favicon_url;
    link.dataset.schoolFavicon = "true";
    if (!favicon) document.head.append(link);
  }, [settings]);

  if (loading) return <LoadingState label="Loading school website..." />;
  if (error || !settings) {
    return <main className="public-school-error"><h1>Website unavailable</h1><p>{error || "This school website could not be found."}</p></main>;
  }

  const style = {
    "--school-primary": settings.primary_color,
    "--school-secondary": settings.secondary_color,
    "--school-font-family": settings.font_family === "serif" ? "Georgia, serif" : settings.font_family === "sans-serif" ? "Arial, sans-serif" : "system-ui, sans-serif",
  } as CSSProperties;
  const content = settings.content?.status === "published" ? settings.content : null;
  const navigation = content?.navigation ?? defaultNavigation;
  const sectionOrder = content?.section_order ?? [];
  const sectionStyle = (key: WebsiteSectionKey): CSSProperties => ({ order: sectionOrder.indexOf(key) < 0 ? 999 : sectionOrder.indexOf(key) });
  const sectionVisible = (key: WebsiteSectionKey): boolean => !content || content.visible_sections.includes(key);

  async function submitContact(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setContactSending(true);
    setContactError("");
    setContactStatus("");
    const formElement = event.currentTarget;
    const form = new FormData(formElement);
    try {
      const result = await api.post<{ message: string }>(`/public/schools/${encodeURIComponent(schoolCode)}/contact`, {
        name: form.get("name"),
        email: form.get("email"),
        phone: form.get("phone"),
        message: form.get("message"),
      });
      setContactStatus(result.message);
      formElement.reset();
    } catch (cause) {
      setContactError(cause instanceof ApiRequestError ? cause.message : "Unable to send your message. Please try again.");
    } finally {
      setContactSending(false);
    }
  }

  return (
    <main className="public-school-site" style={style}>
      <header className="public-school-header">
        <Link className="public-school-brand" href="/">
          {settings.logo_url ? <Image src={settings.logo_url} alt="" width={56} height={56} unoptimized /> : <span className="public-school-mark">{settings.website_name.slice(0, 1).toUpperCase()}</span>}
          <span><strong>{settings.website_name}</strong>{settings.tagline && <small>{settings.tagline}</small>}</span>
        </Link>
        {schoolOptions.length > 1 && <label className="public-school-selector"><span className="visually-hidden">Choose school website</span><select value={settings.school_code} onChange={(event) => {
          const nextSchoolCode = event.target.value;
          setSchoolCode(nextSchoolCode);
          router.push(`/?school=${encodeURIComponent(nextSchoolCode)}`);
        }} aria-label="Choose school website">{schoolOptions.map((school) => <option value={school.school_code} key={school.school_code}>{school.website_name}</option>)}</select></label>}
        <nav aria-label="Website navigation">{navigation.map((item) => <a key={item.id} href={item.href}>{item.label}</a>)}</nav>
        <PublicLoginMenu />
      </header>
      {sliderError && <p className="public-slider-error" role="status">Homepage slides are temporarily unavailable.</p>}
      {sliders.length > 0 && <PublicSliderCarousel sliders={sliders} />}
      <section className="public-school-hero">
        <div className="public-school-hero-content">
          <p className="public-school-eyebrow">Welcome to</p>
          <h1>{settings.website_name}</h1>
          {settings.tagline && <p className="public-school-tagline">{settings.tagline}</p>}
          <a className="public-school-cta" href="#about">Discover our school</a>
        </div>
          {settings.logo_url && <Image className="public-school-hero-logo" src={settings.logo_url} alt={`${settings.website_name} logo`} width={180} height={180} unoptimized />}
      </section>
      {sectionVisible("about") && <section id="about" className="public-school-about">
        <p className="public-school-eyebrow">Who we are</p>
        <h2>About our school</h2>
        <p>{settings.about || settings.tagline || `Welcome to ${settings.website_name}.`}</p>
      </section>}
      {content && <div className="public-content-sections">
        {sectionVisible("welcome") && <section id="welcome" style={sectionStyle("welcome")} className="demo-content-section public-welcome-section">
          <p className="public-school-eyebrow">Welcome</p>
          <h2>{settings.website_name}</h2>
          <p>{content.welcome_message}</p>
        </section>}
        {sectionVisible("statistics") && content.statistics.length > 0 && <section id="statistics" style={sectionStyle("statistics")} className="public-statistics" aria-label="School statistics">
          {content.statistics.map((statistic, index) => <article key={`${statistic.label}-${index}`}><strong>{statistic.value}</strong><span>{statistic.label}</span></article>)}
        </section>}
        {sectionVisible("mission") && <section id="mission" style={sectionStyle("mission")} className="demo-content-section public-mission-grid">
          <article><p className="public-school-eyebrow">Our Mission</p><p>{content.mission}</p></article>
          <article><p className="public-school-eyebrow">Our Vision</p><p>{content.vision}</p></article>
        </section>}
        {sectionVisible("principal") && <section id="principal" style={sectionStyle("principal")} className="demo-content-section public-principal-section">
          {content.principal_message.image_url && <Image src={content.principal_message.image_url} alt="" width={112} height={112} unoptimized />}
          <div><p className="public-school-eyebrow">A message from the principal</p><h2>{content.principal_message.name}</h2><p className="public-principal-designation">{content.principal_message.designation}</p><p>{content.principal_message.message}</p></div>
        </section>}
        {sectionVisible("history") && <section id="history" style={sectionStyle("history")} className="demo-content-section public-history-section"><p className="public-school-eyebrow">Our story</p><h2>School history</h2><p>{content.school_history}</p></section>}
        {sectionVisible("facilities") && <section id="facilities" style={sectionStyle("facilities")} className="demo-content-section public-facilities-section">
          <div><p className="public-school-eyebrow">Learning environment</p><h2>Facilities</h2></div>
          <ul>{content.facilities.map((facility, index) => <li key={`${facility}-${index}`}>{facility}</li>)}</ul>
        </section>}
        {sectionVisible("programs") && content.academic_programs.length > 0 && <section id="programs" style={sectionStyle("programs")} className="demo-content-section public-facilities-section">
          <div><p className="public-school-eyebrow">Learning pathways</p><h2>Academic programs</h2></div>
          <ul>{content.academic_programs.map((program, index) => <li key={`${program}-${index}`}>{program}</li>)}</ul>
        </section>}
        {sectionVisible("achievements") && <section id="achievements" style={sectionStyle("achievements")} className="demo-content-section public-achievements-section"><p className="public-school-eyebrow">Celebrating growth</p><h2>Achievements</h2><ul>{content.achievements.map((achievement, index) => <li key={`${achievement}-${index}`}>{achievement}</li>)}</ul></section>}
        {sectionVisible("gallery") && content.gallery.some((item) => item.is_active && item.status === "published") && <section id="gallery" style={sectionStyle("gallery")} className="demo-content-section public-gallery-section">
          <div><p className="public-school-eyebrow">Moments at school</p><h2>Photo gallery</h2></div>
          <div className="public-gallery-grid">{content.gallery.filter((item) => item.is_active && item.status === "published").map((item) => <article className="public-gallery-card" key={item.id}>
            {item.image_url && <Image src={item.image_url} alt={item.title} width={520} height={320} unoptimized />}
            <div><h3>{item.title}</h3><p>{item.description}</p></div>
          </article>)}</div>
        </section>}
        {sectionVisible("admissions") && <section id="admissions" style={sectionStyle("admissions")} className="demo-content-section public-admissions-section">
          <div><p className="public-school-eyebrow">Join our community</p><h2>Admissions</h2></div><p>{content.admission_information}</p>
        </section>}
        {sectionVisible("staff") && content.staff.length > 0 && <section id="staff" style={sectionStyle("staff")} className="demo-content-section public-staff-section">
          <div><p className="public-school-eyebrow">Our people</p><h2>Teachers and staff</h2></div>
          <div className="public-staff-grid">{content.staff.filter((member) => member.status === "published").map((member) => <article className="public-staff-card" key={member.id}>
            {member.image_url && <Image src={member.image_url} alt="" width={96} height={96} unoptimized />}
            <h3>{member.name}</h3><p className="public-principal-designation">{member.designation}</p><p>{member.bio}</p>
          </article>)}</div>
        </section>}
        {sectionVisible("notices") && <PublishedEntries id="notices" title="Notice board" entries={content.notices} style={sectionStyle("notices")} />}
        {sectionVisible("news") && <PublishedEntries id="news" title="News and events" entries={content.news} style={sectionStyle("news")} />}
      </div>}
      {sectionVisible("contact") && <section id="contact" className="public-school-contact">
        <div><p className="public-school-eyebrow">Get in touch</p><h2>Contact our school</h2></div>
        <div className="public-school-contact-list">
          {settings.contact_email && <a href={`mailto:${settings.contact_email}`}><Mail size={18} />{settings.contact_email}</a>}
          {settings.phone && <a href={`tel:${settings.phone}`}><Phone size={18} />{settings.phone}</a>}
          {settings.address && <a href={`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(settings.address)}`} target="_blank" rel="noreferrer"><MapPin size={18} />{settings.address}</a>}
        </div>
        <form className="public-contact-form" onSubmit={submitContact}>
          <label>Your name<input name="name" autoComplete="name" maxLength={255} required /></label>
          <label>Email address<input name="email" type="email" autoComplete="email" maxLength={255} required /></label>
          <label>Phone (optional)<input name="phone" type="tel" autoComplete="tel" maxLength={50} /></label>
          <label>Message<textarea name="message" rows={4} maxLength={5000} required /></label>
          {contactError && <p role="alert" className="public-contact-error">{contactError}</p>}
          {contactStatus && <p role="status" className="public-contact-success">{contactStatus}</p>}
          <button className="public-school-cta" type="submit" disabled={contactSending}>{contactSending ? "Sending..." : "Send message"}</button>
        </form>
        {settings.address && <iframe
          className="public-school-map"
          title={`Map showing ${settings.website_name}`}
          src={`https://www.google.com/maps?q=${encodeURIComponent(settings.address)}&output=embed`}
          loading="lazy"
          referrerPolicy="no-referrer-when-downgrade"
        />}
      </section>}
      <footer className="public-school-footer">
        <span>{settings.footer_text || settings.website_name}</span>
        <div className="public-school-socials">
          {settings.facebook_url && <a href={settings.facebook_url} target="_blank" rel="noreferrer" aria-label="Facebook"><AtSign size={18} /></a>}
          {settings.instagram_url && <a href={settings.instagram_url} target="_blank" rel="noreferrer" aria-label="Instagram"><AtSign size={18} /></a>}
          {settings.youtube_url && <a href={settings.youtube_url} target="_blank" rel="noreferrer" aria-label="YouTube"><PlayCircle size={18} /></a>}
          <Globe2 size={18} aria-hidden="true" />
        </div>
      </footer>
    </main>
  );
}

function PublicLoginMenu() {
  return <Link className="public-login-trigger" href="/login">Login</Link>;
}

function PublishedEntries({ id, title, entries, style }: { id: string; title: string; entries: WebsiteContentEntry[]; style?: CSSProperties }) {
  const visibleEntries = entries.filter((entry) => entry.status === "published");
  if (!visibleEntries.length) return null;

  return <section id={id} style={style} className="demo-content-section public-entries-section">
    <div><p className="public-school-eyebrow">Latest updates</p><h2>{title}</h2></div>
    <div className="public-entries-grid">{visibleEntries.map((entry) => <article className="public-entry-card" key={entry.id}>
      <div className="public-entry-meta">{entry.date && <time dateTime={entry.date}>{new Date(`${entry.date}T00:00:00`).toLocaleDateString()}</time>}{entry.is_demo && <span className="public-demo-badge">Demo content</span>}</div>
      <h3>{entry.title}</h3><p>{entry.description}</p>
    </article>)}</div>
  </section>;
}

function PublicSliderCarousel({ sliders }: { sliders: HomepageSlider[] }) {
  const [currentIndex, setCurrentIndex] = useState(0);
  const [imageUnavailable, setImageUnavailable] = useState<number[]>([]);
  const touchStartX = useRef<number | null>(null);
  const current = sliders[currentIndex];
  if (!current) return null;

  function previous() {
    setCurrentIndex((index) => (index - 1 + sliders.length) % sliders.length);
  }

  function next() {
    setCurrentIndex((index) => (index + 1) % sliders.length);
  }

  return (
    <section className="public-slider" aria-label="Homepage highlights" onTouchStart={(event) => { touchStartX.current = event.touches[0]?.clientX ?? null; }} onTouchEnd={(event) => {
      const start = touchStartX.current;
      const end = event.changedTouches[0]?.clientX;
      touchStartX.current = null;
      if (start === null || end === undefined || Math.abs(end - start) < 48 || sliders.length < 2) return;
      if (end < start) next();
      else previous();
    }}>
      <div className="public-slider-image">
        {!imageUnavailable.includes(current.id) ? (
          <picture>
            {current.mobile_image_url && <source media="(max-width: 640px)" srcSet={current.mobile_image_url} />}
            <Image src={current.image_url} alt={current.title} fill priority={currentIndex === 0} sizes="100vw" unoptimized onError={() => setImageUnavailable((ids) => ids.includes(current.id) ? ids : [...ids, current.id])} />
          </picture>
        ) : <div className="public-slider-image-fallback" aria-hidden="true" />}
      </div>
      <div className="public-slider-shade" />
      <div className="public-slider-copy" aria-live="polite">
        {current.subtitle && <p className="public-slider-subtitle">{current.subtitle}</p>}
        <h2>{current.title}</h2>
        {current.description && <p>{current.description}</p>}
        {current.button_text && current.button_url && <a className="public-slider-button" href={current.button_url} {...(/^https?:\/\//i.test(current.button_url) ? { target: "_blank", rel: "noreferrer" } : {})}>{current.button_text}</a>}
      </div>
      {sliders.length > 1 && <>
        <button className="public-slider-control public-slider-previous" type="button" aria-label="Previous slide" onClick={previous}><ChevronLeft size={24} /></button>
        <button className="public-slider-control public-slider-next" type="button" aria-label="Next slide" onClick={next}><ChevronRight size={24} /></button>
        <div className="public-slider-pagination" role="group" aria-label="Choose slide">
          {sliders.map((slider, index) => <button key={slider.id} type="button" aria-label={`Slide ${index + 1}: ${slider.title}`} aria-current={index === currentIndex ? "true" : undefined} className={index === currentIndex ? "public-slider-dot public-slider-dot-active" : "public-slider-dot"} onClick={() => setCurrentIndex(index)} />)}
        </div>
      </>}
    </section>
  );
}
