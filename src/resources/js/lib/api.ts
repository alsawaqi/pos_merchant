/**
 * Shared HTTP client for the merchant SPA. Mirrors pos_admin's
 * api.ts wrapper — CSRF auto-retry, 401/419 → /login interceptor,
 * standardised ApiError shape.
 *
 * Three production behaviours:
 *
 *  - Auth interceptor: 401 (or 419 after a CSRF retry already
 *    happened) triggers a hard navigation to /login. The current
 *    URL is preserved as ?redirect=<intended> so the user lands
 *    where they were after re-authenticating. Components don't
 *    need to handle "session expired" — every call is guarded.
 *
 *  - CSRF auto-retry: a 419 fetches a fresh token from /auth/csrf,
 *    updates the meta tag, replays the request once.
 *
 *  - /auth/user is the ONE legitimate consumer of a 401 (it's how
 *    the SPA asks "is anyone signed in?"). skipAuthInterceptor
 *    opts that single call out of the redirect.
 */

const CSRF_ENDPOINT = '/auth/csrf';
const AUTH_PROBE_ENDPOINT = '/auth/user';
const LOGIN_PATH = '/login';

export type JsonValue =
    | null
    | boolean
    | number
    | string
    | JsonValue[]
    | { [key: string]: JsonValue };

export interface ApiRequestOptions extends Omit<RequestInit, 'body' | 'headers'> {
    body?: JsonValue;
    headers?: Record<string, string>;
    query?: Record<string, string | number | boolean | null | undefined>;
    /** Opt this single call out of the global 401/419 redirect. */
    skipAuthInterceptor?: boolean;
    /** Internal: set when retrying after a 419 to prevent infinite recursion. */
    _csrfRetried?: boolean;
}

export interface ValidationErrorPayload {
    message?: string;
    errors: Record<string, string[]>;
}

export class ApiError extends Error {
    public constructor(
        public readonly status: number,
        public readonly payload: unknown,
        message?: string,
    ) {
        super(message ?? `Request failed with status ${status}`);
        this.name = 'ApiError';
    }

    public isValidationError(): this is ApiError & { payload: ValidationErrorPayload } {
        return this.status === 422 && hasValidationErrors(this.payload);
    }

    public firstValidationMessage(): string | null {
        if (!this.isValidationError()) {
            return null;
        }

        for (const messages of Object.values(this.payload.errors)) {
            if (Array.isArray(messages) && messages.length > 0) {
                return messages[0] ?? null;
            }
        }
        return null;
    }
}

function hasValidationErrors(payload: unknown): payload is ValidationErrorPayload {
    return (
        payload !== null &&
        typeof payload === 'object' &&
        'errors' in payload &&
        typeof (payload as { errors?: unknown }).errors === 'object'
    );
}

function csrfTokenFromMeta(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

async function refreshCsrfToken(): Promise<string> {
    const response = await fetch(CSRF_ENDPOINT, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
        return csrfTokenFromMeta();
    }

    const body = (await response.json()) as { token?: string };
    if (body?.token) {
        const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
        if (meta) {
            meta.content = body.token;
        }
        return body.token;
    }
    return csrfTokenFromMeta();
}

function buildUrl(url: string, query: ApiRequestOptions['query']): string {
    if (!query) {
        return url;
    }
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(query)) {
        if (value === undefined || value === null || value === '') {
            continue;
        }
        params.append(key, String(value));
    }
    const qs = params.toString();
    return qs ? `${url}?${qs}` : url;
}

async function apiRequest<T>(method: string, url: string, options: ApiRequestOptions = {}): Promise<T> {
    const finalUrl = buildUrl(url, options.query);

    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(options.headers ?? {}),
    };

    let body: BodyInit | undefined;
    if (options.body !== undefined) {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(options.body);
    }

    if (method !== 'GET' && method !== 'HEAD') {
        const token = csrfTokenFromMeta();
        if (token) {
            headers['X-CSRF-TOKEN'] = token;
        }
    }

    const response = await fetch(finalUrl, {
        method,
        credentials: 'same-origin',
        headers,
        body,
    });

    if (response.ok) {
        if (response.status === 204) {
            return undefined as unknown as T;
        }
        const text = await response.text();
        return text ? (JSON.parse(text) as T) : (undefined as unknown as T);
    }

    let payload: unknown = null;
    try {
        payload = await response.json();
    } catch {
        payload = null;
    }

    // CSRF token mismatch — fetch a fresh one + replay exactly once.
    if (response.status === 419 && !options._csrfRetried) {
        await refreshCsrfToken();
        return apiRequest<T>(method, url, { ...options, _csrfRetried: true });
    }

    // 401 → bounce to /login unless the caller opted out (only
    // /auth/user does that to probe the current sign-in state).
    if (response.status === 401 && !options.skipAuthInterceptor && url !== AUTH_PROBE_ENDPOINT) {
        const redirect = encodeURIComponent(window.location.pathname + window.location.search);
        window.location.href = `${LOGIN_PATH}?redirect=${redirect}`;
        // Throw to halt any caller waiting on the response — they
        // never get a chance to render against stale auth state.
        throw new ApiError(response.status, payload);
    }

    throw new ApiError(response.status, payload);
}

export function apiGet<T>(url: string, options: Omit<ApiRequestOptions, 'body' | 'method'> = {}): Promise<T> {
    return apiRequest<T>('GET', url, options);
}

/**
 * GET a file download as a Blob (Phase D6 report exports). Same
 * headers/credentials as apiRequest — the api group's RequireJsonRequest
 * middleware 406s anything without Accept: application/json, so plain
 * <a href> downloads are impossible; the SPA fetches the bytes and
 * object-URLs them instead. Error bodies are still JSON → ApiError.
 */
export async function apiDownload(url: string): Promise<{ blob: Blob; filename: string | null }> {
    const response = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        let payload: unknown = null;
        try {
            payload = await response.json();
        } catch {
            payload = null;
        }

        if (response.status === 401) {
            const redirect = encodeURIComponent(window.location.pathname + window.location.search);
            window.location.href = `${LOGIN_PATH}?redirect=${redirect}`;
        }

        throw new ApiError(response.status, payload);
    }

    const disposition = response.headers.get('Content-Disposition') ?? '';
    const filename = /filename="([^"]+)"/.exec(disposition)?.[1] ?? null;

    return { blob: await response.blob(), filename };
}

export function apiPost<T>(url: string, body?: JsonValue, options: Omit<ApiRequestOptions, 'body' | 'method'> = {}): Promise<T> {
    return apiRequest<T>('POST', url, { ...options, body });
}

export function apiPatch<T>(url: string, body?: JsonValue, options: Omit<ApiRequestOptions, 'body' | 'method'> = {}): Promise<T> {
    return apiRequest<T>('PATCH', url, { ...options, body });
}

// PUT for idempotent full-state replacements (e.g. Phase 4.9
// product addon-group sync). PATCH would imply partial update;
// PUT here means "this is the complete desired set".
export function apiPut<T>(url: string, body?: JsonValue, options: Omit<ApiRequestOptions, 'body' | 'method'> = {}): Promise<T> {
    return apiRequest<T>('PUT', url, { ...options, body });
}

export function apiDelete<T>(url: string, options: Omit<ApiRequestOptions, 'body' | 'method'> = {}): Promise<T> {
    return apiRequest<T>('DELETE', url, options);
}
