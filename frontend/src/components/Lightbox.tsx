import { useEffect, useRef } from 'react'
import type { Photo } from '../types'

interface LightboxProps {
  photos: Photo[]
  index: number
  onClose: () => void
  onNavigate: (index: number) => void
}

const SWIPE_THRESHOLD_PX = 50

export function Lightbox({ photos, index, onClose, onNavigate }: LightboxProps) {
  const touchStartX = useRef<number | null>(null)
  const photo = photos[index]
  const hasPrevious = index > 0
  const hasNext = index < photos.length - 1

  useEffect(() => {
    const handleKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        onClose()
      } else if (event.key === 'ArrowLeft' && hasPrevious) {
        onNavigate(index - 1)
      } else if (event.key === 'ArrowRight' && hasNext) {
        onNavigate(index + 1)
      }
    }

    window.addEventListener('keydown', handleKey)

    return () => window.removeEventListener('keydown', handleKey)
  }, [hasNext, hasPrevious, index, onClose, onNavigate])

  if (!photo) {
    return null
  }

  return (
    <div
      className="lightbox"
      role="dialog"
      aria-modal="true"
      aria-label="Fotografija"
      onTouchStart={(event) => {
        touchStartX.current = event.touches[0]?.clientX ?? null
      }}
      onTouchEnd={(event) => {
        if (touchStartX.current === null) {
          return
        }

        const delta = (event.changedTouches[0]?.clientX ?? 0) - touchStartX.current
        touchStartX.current = null

        if (delta < -SWIPE_THRESHOLD_PX && hasNext) {
          onNavigate(index + 1)
        } else if (delta > SWIPE_THRESHOLD_PX && hasPrevious) {
          onNavigate(index - 1)
        }
      }}
    >
      <button type="button" className="lightbox-close" onClick={onClose} aria-label="Zatvori">
        ×
      </button>

      {hasPrevious && (
        <button
          type="button"
          className="lightbox-nav prev"
          onClick={() => onNavigate(index - 1)}
          aria-label="Prethodna"
        >
          ‹
        </button>
      )}

      <img src={photo.web_url} alt="" onClick={onClose} />

      {hasNext && (
        <button
          type="button"
          className="lightbox-nav next"
          onClick={() => onNavigate(index + 1)}
          aria-label="Sljedeća"
        >
          ›
        </button>
      )}
    </div>
  )
}
