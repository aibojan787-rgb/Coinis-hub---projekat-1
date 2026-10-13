export interface Photo {
  uuid: string
  thumb_url: string
  web_url: string
  width: number
  height: number
  created_at: string
}

export interface UpdatedPhoto extends Photo {
  cursor_id: number
}

export interface GalleryPage {
  data: Photo[]
  links: { next: string | null }
  latest_id: number
  server_time: number
}

export interface UpdatesPage {
  data: UpdatedPhoto[]
  server_time: number
  has_more: boolean
}

export interface SinglePhoto {
  data: Photo
}
