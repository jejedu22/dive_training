const TOKEN_KEY = 'dive_token'

export class ApiError extends Error {
  /** fromApi = false : la réponse ne vient pas de Symfony. */
  constructor(public status: number, message: string, public fromApi = true) { super(message) }
}

export const getToken = () => localStorage.getItem(TOKEN_KEY)
export const setToken = (t: string | null) => (t ? localStorage.setItem(TOKEN_KEY, t) : localStorage.removeItem(TOKEN_KEY))

export async function api<T>(method: string, path: string, body?: unknown): Promise<T> {
  const res = await fetch(`/api${path}`, {
    method,
    headers: { 'Content-Type': 'application/json', ...(getToken() ? { Authorization: `Bearer ${getToken()}` } : {}) },
    body: body === undefined ? undefined : JSON.stringify(body),
  })
  if (res.status === 401 && path !== '/login_check' && path !== '/me/password') {
    setToken(null)
    window.location.assign('/login')
  }
  if (res.status === 204) return undefined as T
  const isJson = (res.headers.get('content-type') ?? '').includes('json')
  const data = isJson ? await res.json().catch(() => ({})) : {}
  if (!res.ok) {
    // Réponse non JSON : la requête n'a pas atteint Symfony (proxy, Traefik, serveur front…)
    if (!isJson) throw new ApiError(res.status, `Réponse ${res.status} qui ne vient pas de l'API (routage /api ?)`, false)
    throw new ApiError(res.status, data.message ?? data.detail ?? data.title ?? `Erreur ${res.status}`)
  }
  if (!isJson) throw new ApiError(res.status, "Réponse qui ne vient pas de l'API : /api est routé vers le front (Traefik)", false)
  return data as T
}

export const get = <T>(p: string) => api<T>('GET', p)
export const post = <T>(p: string, b?: unknown) => api<T>('POST', p, b ?? {})
export const put = <T>(p: string, b: unknown) => api<T>('PUT', p, b)
export const del = <T>(p: string) => api<T>('DELETE', p)
