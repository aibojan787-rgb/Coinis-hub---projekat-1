import type { GalleryPage, Photo, SinglePhoto, UpdatesPage } from '../types'

const API_BASE = import.meta.env.VITE_API_URL ?? ''

export const MAX_UPLOAD_BYTES = 50 * 1024 * 1024

const SUPPORTED_TYPES = ['image/jpeg', 'image/png', 'image/webp']
const HEIC_TYPES = ['image/heic', 'image/heif']

export class ApiError extends Error {
  readonly status: number

  constructor(status: number, message: string) {
    super(message)
    this.status = status
  }
}

/**
 * Returns a user-facing message (in Bosnian) if the file cannot be uploaded, otherwise null.
 */
export function validateFile(file: File): string | null {
  if (file.size > MAX_UPLOAD_BYTES) {
    return 'Fotografija je veća od 50 MB.'
  }

  if (HEIC_TYPES.includes(file.type)) {
    return 'HEIC fotografije nisu podržane. Promijenite format kamere na JPEG.'
  }

  if (file.type && !SUPPORTED_TYPES.includes(file.type)) {
    return 'Podržani su samo JPEG, PNG i WebP formati.'
  }

  return null
}

function resolveUrl(path: string): string {
  return `${API_BASE}${path}`
}

/**
 * Pagination links come back as absolute URLs from the backend. Keep only the
 * path and query so requests go through the same origin as everything else.
 */
function toApiPath(url: string): string {
  if (!url.startsWith('http')) {
    return url
  }

  const parsed = new URL(url)

  return `${parsed.pathname}${parsed.search}`
}

async function getJson<T>(path: string): Promise<T | null> {
  const response = await fetch(resolveUrl(path), { headers: { Accept: 'application/json' } })

  if (response.status === 404 || response.status === 304) {
    return null
  }

  if (!response.ok) {
    throw new ApiError(response.status, 'Učitavanje nije uspjelo.')
  }

  return (await response.json()) as T
}

export function fetchGalleryPage(path = '/api/photos'): Promise<GalleryPage | null> {
  return getJson<GalleryPage>(toApiPath(path))
}

export function fetchUpdates(afterId: number): Promise<UpdatesPage | null> {
  return getJson<UpdatesPage>(`/api/photos/updates?after_id=${afterId}`)
}

export async function fetchPhoto(uuid: string): Promise<Photo | null> {
  const body = await getJson<SinglePhoto>(`/api/photos/${uuid}`)

  return body?.data ?? null
}

export interface UploadResult {
  uuid: string
}

interface ErrorBody {
  message?: string
}

function parseBody(text: string): unknown {
  try {
    return JSON.parse(text)
  } catch {
    return null
  }
}

function uploadErrorMessage(status: number, body: unknown): string {
  if (status === 429) {
    return 'Previše slika u kratkom roku. Pokušajte kasnije.'
  }

  if (status === 413 || status === 422) {
    return 'Fotografija nije podržana ili je prevelika (maks. 50 MB, JPEG, PNG ili WebP).'
  }

  const message = (body as ErrorBody | null)?.message

  return message ?? 'Slanje nije uspjelo. Pokušajte ponovo.'
}

/**
 * Upload one photo. XMLHttpRequest is used instead of fetch because fetch
 * does not report upload progress.
 */
export function uploadPhoto(file: File, onProgress: (fraction: number) => void): Promise<UploadResult> {
  return new Promise((resolve, reject) => {
    const request = new XMLHttpRequest()

    request.open('POST', resolveUrl('/api/photos'))
    request.setRequestHeader('Accept', 'application/json')

    request.upload.onprogress = (event) => {
      if (event.lengthComputable) {
        onProgress(event.loaded / event.total)
      }
    }

    request.onload = () => {
      const body = parseBody(request.responseText)

      if (request.status === 202) {
        resolve(body as UploadResult)

        return
      }

      reject(new ApiError(request.status, uploadErrorMessage(request.status, body)))
    }

    request.onerror = () => {
      reject(new ApiError(0, 'Greška na mreži. Pokušajte ponovo.'))
    }

    const form = new FormData()
    form.append('photo', file)
    request.send(form)
  })
}
