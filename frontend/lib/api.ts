import type { ApiError } from "@/types";

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8000/api";

export class ApiRequestError extends Error {
  status: number;
  details?: ApiError;
  constructor(message: string, status: number, details?: ApiError) { super(message); this.status = status; this.details = details; }
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const token = typeof window !== "undefined" ? window.localStorage.getItem("school-saas-token") : null;
  const isFormDataBody = typeof FormData !== "undefined" && options.body instanceof FormData;
  const response = await fetch(`${API_URL}${path}`, {
    ...options,
    headers: { Accept: "application/json", ...(!isFormDataBody ? { "Content-Type": "application/json" } : {}), ...(token ? { Authorization: `Bearer ${token}` } : {}), ...options.headers },
  });
  let body: unknown;
  try {
    body = await response.json();
  } catch {
    throw new ApiRequestError(response.ok ? "The server returned an invalid response." : "The server returned an invalid error response.", response.status);
  }
  if (!response.ok) {
    const details = isApiError(body) ? body : undefined;
    if ((response.status === 401 || details?.code === "account_inactive") && typeof window !== "undefined") window.dispatchEvent(new Event("school-saas:unauthorized"));
    throw new ApiRequestError(details?.message ?? "Something went wrong.", response.status, details);
  }
  return body as T;
}

function isApiError(value: unknown): value is ApiError {
  return typeof value === "object" && value !== null && "message" in value && typeof value.message === "string";
}

export const api = {
  get: <T>(path: string) => request<T>(path),
  post: <T>(path: string, data: unknown) => request<T>(path, { method: "POST", body: JSON.stringify(data) }),
  postForm: <T>(path: string, data: FormData) => request<T>(path, { method: "POST", body: data }),
  put: <T>(path: string, data: unknown) => request<T>(path, { method: "PUT", body: JSON.stringify(data) }),
  patch: <T>(path: string, data: unknown) => request<T>(path, { method: "PATCH", body: JSON.stringify(data) }),
  delete: <T>(path: string) => request<T>(path, { method: "DELETE" }),
};
