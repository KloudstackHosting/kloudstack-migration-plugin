# Changelog

All notable changes to the KloudStack Migration Plugin will be documented here.

## [1.2.0] - 2026-03-22

### Added
- `POST /cancel-jobs` REST endpoint: clears the export queue and marks pending job
  transients as `cancelled`. Accepts an optional `{ "job_ids": [...] }` body to cancel
  specific jobs; when omitted, flushes the entire queue.
- KloudStack backend now calls `/cancel-jobs` best-effort whenever a migration fails or
  is paused, so orphaned export jobs are not left running on the source site.

## [1.1.0] - 2026-03-21

### Fixed
- WP-Cron not processing queued export jobs on low-traffic sites: after enqueuing a DB
  or media job, the endpoint now immediately fires a non-blocking request to
  `/?doing_wp_cron` so the job starts processing without waiting for organic site traffic.

### Added
- `discover()` endpoint now returns `wp_cron_enabled`, `cron_next_scheduled`, and
  `jobs_in_queue` so the migration agent can flag WP-Cron problems during profiling.

## [1.0.0] - 2026-03-18

### Added
- Initial release
- REST endpoint: site validation and connectivity check
- REST endpoint: database export (streamed)
- REST endpoint: media file enumeration and upload
- Admin settings page with token configuration and connection status
- Background export support via `BackgroundExport`
- WordPress 6.0+ and PHP 8.1+ compatibility
