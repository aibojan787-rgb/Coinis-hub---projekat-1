import { useCallback, useEffect, useRef, useState } from 'react'
import { fetchGalleryPage, fetchUpdates } from '../api/photos'
import type { Photo } from '../types'

export const POLL_INTERVAL_MS = 5000

function prependNewest(current: Photo[], incoming: Photo[]): Photo[] {
  const known = new Set(current.map((photo) => photo.uuid))
  const fresh = incoming.filter((photo) => !known.has(photo.uuid))

  return fresh.length > 0 ? [...[...fresh].reverse(), ...current] : current
}

function appendOldest(current: Photo[], incoming: Photo[]): Photo[] {
  const known = new Set(current.map((photo) => photo.uuid))

  return [...current, ...incoming.filter((photo) => !known.has(photo.uuid))]
}

export function useGallery() {
  const [photos, setPhotos] = useState<Photo[]>([])
  const [nextPath, setNextPath] = useState<string | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [hasError, setHasError] = useState(false)
  const latestIdRef = useRef(0)
  const isLoadingMoreRef = useRef(false)

  useEffect(() => {
    let cancelled = false

    fetchGalleryPage()
      .then((page) => {
        if (cancelled || !page) {
          return
        }

        latestIdRef.current = page.latest_id
        setPhotos(page.data)
        setNextPath(page.links.next)
        setHasError(false)
      })
      .catch(() => {
        if (!cancelled) {
          setHasError(true)
        }
      })
      .finally(() => {
        if (!cancelled) {
          setIsLoading(false)
        }
      })

    return () => {
      cancelled = true
    }
  }, [])

  // Polling starts only after the first page is loaded, so latestIdRef is set.
  useEffect(() => {
    if (isLoading) {
      return
    }

    const poll = async () => {
      if (document.hidden) {
        return
      }

      try {
        const updates = await fetchUpdates(latestIdRef.current)

        if (!updates || updates.data.length === 0) {
          return
        }

        latestIdRef.current = Math.max(latestIdRef.current, ...updates.data.map((photo) => photo.cursor_id))
        setPhotos((current) => prependNewest(current, updates.data))
      } catch {
        // Keep the current gallery; the next tick tries again.
      }
    }

    const timer = window.setInterval(poll, POLL_INTERVAL_MS)
    document.addEventListener('visibilitychange', poll)

    return () => {
      window.clearInterval(timer)
      document.removeEventListener('visibilitychange', poll)
    }
  }, [isLoading])

  const loadMore = useCallback(async () => {
    if (!nextPath || isLoadingMoreRef.current) {
      return
    }

    isLoadingMoreRef.current = true

    try {
      const page = await fetchGalleryPage(nextPath)

      if (page) {
        setPhotos((current) => appendOldest(current, page.data))
        setNextPath(page.links.next)
      }
    } catch {
      setHasError(true)
    } finally {
      isLoadingMoreRef.current = false
    }
  }, [nextPath])

  const addPhoto = useCallback((photo: Photo) => {
    setPhotos((current) => prependNewest(current, [photo]))
  }, [])

  return {
    photos,
    isLoading,
    hasError,
    hasMore: nextPath !== null,
    loadMore,
    addPhoto,
  }
}
