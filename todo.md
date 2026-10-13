# TODO: Improvements

Last updated: 2026-10-14. Ordered by priority. Items marked **(bug)** are problems in the code, not new features.

Effort guide: **S** = under half a day, **M** = a day or two, **L** = several days.

---

## Done

| What | Result |
| ---- | ------ |
| **Download** | Lightbox has **Preuzmi** (normal download, every device) and **Podijeli** (share sheet with "Save Image", phones only). The server sends a high quality JPEG copy (max 3000 px, quality 90, no GPS). Endpoint: `GET /api/photos/{uuid}/download`, 120 per minute per IP |
| **Compression and disk use** | Every photo is stored as `thumb.webp` (400 px), `web.webp` (1600 px) and `download.jpg`. The raw upload is **deleted** after processing (`PHOTOS_KEEP_ORIGINALS=false` by default). Measured on a real phone photo: 882 KB + 171 KB + 32 KB, about 1.1 MB per photo |
| **GPS privacy** | None of the three stored copies contain GPS or camera data |
| **Live gallery bug** | Polling also asks for everything that finished since the last poll (`since`, `server_time`, `has_more`), so a photo that finishes late is no longer missed |
| **Disk write failures** | Storage disks now raise errors. A failed save answers 503 and creates no photo. A failed processing marks the photo `failed` |
| **Upload error messages** | Clear Bosnian messages: too big, too many pixels, HEIC, not an image, server problem, rate limit. The failed tile shows the faded photo |
| **Fast failure report** | `GET /api/photos/{uuid}/status`: the uploader sees "processing failed" within seconds, not after 3 minutes |
| **One-command tests** | `bash scripts/test.sh <url>`: 58 backend tests (run inside the app's Docker image), frontend lint and build, and a 21-check live test (`scripts/smoke-test.sh`). Last run: everything passed |
| **Manual clean-up** | Documented commands to delete one photo or all photos (database row plus both folders) |

---

## P0: next, before a real event

### 1. Check the quality of the download copy (S)

Compare a real phone photo with its download copy zoomed in. Decide the final `PHOTOS_DOWNLOAD_MAX_EDGE` and `PHOTOS_DOWNLOAD_QUALITY`. A typical 12 MP phone photo (4032x3024) becomes 3000x2250, about 55% of the pixels. `PHOTOS_DOWNLOAD_MAX_EDGE=4000` keeps the full 12 MP.

### 2. Retention: automatic clean-up of old photos (M)

Nothing deletes photos yet. Decided direction: keep photos longer than a week so clients are not forced to download quickly, then remove them automatically.
- A scheduled command that deletes photos older than N days (suggested default **30**, set in `.env`). Removes the database row, `private/photos/<uuid>` and `public/photos/<uuid>` together.
- A dry-run mode that only lists what would be deleted.
- A switch to turn it off for special events.
- A disk usage warning before the disk fills.

### 3. Backups for the Docker setup (S)

`backend/deploy/backup.sh` is for a bare-metal install and does not work with Docker. Needed: a script that dumps the database (`docker compose exec db mariadb-dump ...`) and archives the `PhotoGallery-Storage` volume into a dated folder, keeps the last 14, and has the restore commands in its header. Test a restore once. Without this, a lost disk or a deleted volume loses every photo.

### 4. Test on real phones (S), in progress

You are testing on your phone. Still to check:
- Does `capture="environment"` open the camera directly on iPhone and Android?
- Do iPhone photos upload (HEIC is rejected by the app)?
- **Download and Podijeli** on both platforms: where does the file end up, does the quality look right?
- Two phones uploading at the same moment: do both photos appear on both phones? (tests the live-update fix)
- Upload speed on mobile data.

### 5. Admin: delete and hide photos (M), later

Until then, use the manual delete commands. Needs an admin login (a single shared secret is enough to start), delete (row and both folders), and optionally hide or "approve before showing".

---

## P1: important for running this as a business

### 6. Events (separate galleries) (L)

Today there is one global gallery. For several clients you need an `events` table, a QR code and link per event (`/e/{slug}`), and per-event settings (upload window, rate limit, moderation, retention, admin access). This changes the schema and every endpoint, so decide it **before** adding more features on top of the single-gallery model.

### 7. Download everything as a ZIP, and select several (M)

- ZIP of all photos for the host. Stream it (for example with ZipStream), never build it in memory.
- Select several photos in the grid and download them.

### 8. Live wall / slideshow page for a TV (M)

A full-screen page with automatic transitions and the event QR code in a corner. Uses the existing polling endpoint.

### 9. Let real guests in (S to M), parked

Only devices on your tailnet can open the page. Options: Tailscale Funnel, or a public domain with a normal HTTPS certificate. Camera access needs valid HTTPS. You decided to focus on the app first.

### 10. Monitoring and alerts (S to M)

Alert on failed jobs or `failed` photos, an uptime check on `/up`, disk and memory alerts, and Docker log rotation.

### 11. Privacy and consent (S)

A short notice for guests that photos are visible to everyone with the link, and a way to ask for removal (needs item 5).

### 12. Real-time updates instead of polling (M to L)

Polling every 5 seconds works. Laravel Reverb or Server-Sent Events would make photos appear instantly. Optional now that the live-update bug is fixed.

---

## P2: quality and polish

### 13. Upload rate limit (S), parked

30 uploads per hour per IP. You decided it is fine for now because most guests use mobile data. If a party shares one Wi-Fi, raise `PHOTOS_UPLOAD_RATE_LIMIT` in `.env`. Rejected uploads also count against the limit.

### 14. Automated checks (M)

- Run the tests in CI (GitHub Actions) and build the Docker image there.
- A test with the real queue driver and two workers (the tests use `QUEUE_CONNECTION=sync`).
- A test for the job failing when a disk write fails (only the disk setting is covered now).
- Frontend tests; none exist.
- `scripts/test.sh` downloads about 116 packages on every run. Keep a composer cache in a Docker volume.
- `scripts/smoke-test.sh` leaves one test photo in the gallery on every run. Make it delete its photo once there is an admin delete.

### 15. Housekeeping (S)

- `backend/composer.lock` is out of date after changing PHP to `^8.4`. Run `composer update --lock` in `backend/` and commit it.
- Bring `ARCHITECTURE.md` up to date with all the changes above (download copy, status endpoint, `since` polling, tests, scripts).
- Replace `backend/README.md` and `frontend/README.md` with short pointers to the root `README.md`.
- Mark `backend/DEPLOY.md` as the bare-metal alternative.

### 16. Security hardening (S to M)

`Content-Security-Policy` and `Strict-Transport-Security` headers, bot protection on uploads if the gallery is public on the internet, a database user with minimal rights, pinned image versions (`mariadb:lts` and `php:8.4-fpm-bookworm` move over time).

### 17. API consistency (S)

- `width` and `height` describe the raw upload, not the 1600 px version.
- `size_bytes` is the web version's size and is named misleadingly.
- `links.next` can contain `http://127.0.0.1/...` behind the proxy. The frontend strips it; fix at the source.
- `cursor_id` exposes the internal photo count.

### 18. HEIC support (M)

If phone testing shows iPhone photos are rejected, convert on the phone before upload or add Imagick with libheif on the server.

### 19. Frontend polish (S to M)

Texts are hard-coded in Bosnian. Allow picking several photos at once. A retry button on failed uploads instead of only "Ukloni". A "new photos" indicator.

---

## Suggested order

1. Quality check of the download copy (1) and the real phone test (4).
2. Backups (3).
3. Retention and clean-up (2).
4. Events (6) before the live wall (8) and ZIP export (7).
5. Admin delete (5), then monitoring (10) and privacy notice (11).
