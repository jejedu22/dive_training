const TOKEN_KEY = 'dive_token'

export class ApiError extends Error {
  constructor(public status: number, message: string) { super(message) }
}

export const getToken = () => localStorage.getItem(TOKEN_KEY)
export const setToken = (t: string | null) => (t ? localStorage.setItem(TOKEN_KEY, t) : localStorage.removeItem(TOKEN_KEY))

export async function api<T>(method: string, path: string, body?: unknown): Promise<T> {
  const res = await fetch(`/api${path}`, {
    method,
    headers: { 'Content-Type': 'application/json', ...(getToken() ? { Authorization: `Bearer ${getToken()}` } : {}) },
    body: body === undefined ? undefined : JSON.stringify(body),
  })
  if (res.status === 401 && path !== '/login_check') {
    setToken(null)
    window.location.assign('/login')
  }
  if (res.status === 204) return undefined as T
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new ApiError(res.status, data.message ?? data.detail ?? data.title ?? `Erreur ${res.status}`)
  return data as T
}

export const get = <T>(p: string) => api<T>('GET', p)
export const post = <T>(p: string, b?: unknown) => api<T>('POST', p, b ?? {})
export const put = <T>(p: string, b: unknown) => api<T>('PUT', p, b)
export const del = <T>(p: string) => api<T>('DELETE', p)
