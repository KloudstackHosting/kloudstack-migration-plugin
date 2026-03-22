# Changelog

All notable changes to the KloudStack Migration Plugin will be documented here.

## [1.2.7] - 2026-07-14

### Added
- **`/export-site-content` endpoint**: New consolidated REST endpoint that accepts a
  `sas_urls` map (keyed by artifact: `plugins`, `themes`, `media`, `mu-plugins`,
  `custom-root`) plus optional `hints`, creates one background job per artifact, and
  returns `{ "jobs": { "<artifact>": "<job_id>" } }` with HTTP 202. Replaces the need
  to call `/upload-media` separately for each artifact type.
- **`BackgroundExport::_run_content_export()`**: New handler that ZIPs a given source
  directory (resolved from the artifact name) and uploads it to Azure Blob via SAS PUT.
  Supports the same `skip_extensions` and `max_file_size_mb` agent hints as
  `_run_media_upload()`. The special `'custom-root'` artifact exports only root-level
  files in `WP_CONTENT_DIR`, excluding standard sub-directories.
- **`BackgroundExport::enqueue()` extended**: Accepts an optional `$extra_data` array
  merged into the queue item, enabling the new `content_export` job type to carry
  `source_path` without breaking existing `db_export`/`media_upload` callers.

## [1.2.6] - 2026-03-22

### Added
- **Server-side hosting detection** (`/discover`): New fields `hosting_platform`,
  `exec_available`, `php_memory_limit_mb`, `php_max_execution_time`, and `disk_free_mb`
  added to the `/discover` response. `hosting_platform` is detected via environment
  variables and constants (`azure_app_service`, `wpe`, `wpvip`, `kinsta`, `other`),
  giving the migration agent reliable server-side truth rather than URL heuristics.
- **Agent hints channel**: `export_db` and `upload_media` endpoints now accept an
  optional `hints` JSON object in the request body. The hints are sanitised and stored
  in the job transient so `BackgroundExport` can apply them during processing.
- **Adaptive gzip compression**: `BackgroundExport::_run_db_export()` reads
  `gzip_level` from agent hints (default `4`). On a retry after a CPU timeout the
  agent can send `gzip_level: 1` to trade file size for significantly lower CPU cost.
- **Selective media export**: `BackgroundExport::_run_media_upload()` reads
  `skip_extensions` (array, e.g. `[".mp4",".mkv"]`) and `max_file_size_mb` (int)
  from agent hints. Files matching either filter are skipped and counted; the total
  is logged and stored in the job record so the agent can report on skipped content.

## [1.2.5] - 2026-03-22

### Fixed
- **Concurrency lock**: `process_queue()` now acquires a 10-minute transient mutex
  before processing. Prevents double-execution when the shutdown function and
  WP-Cron both fire simultaneously, which previously doubled CPU/memory usage.
- **Memory — streaming upload**: `_upload_file_to_blob()` now uses cURL streaming
  PUT instead of `wp_remote_request()` with `file_get_contents()`. The old approach
  loaded the entire file into PHP memory, causing OOM crashes on Azure App Service
  (default 128 MB limit) with large databases or media archives.
- **CPU — gzip level**: Changed `gzip -9` (max compression) to `gzip -4` in the
  mysqldump pipeline. `-9` caused CPU spikes on Azure App Service Consumption plans;
  `-4` gives a good compression ratio with significantly lower CPU cost.
- **ZIP progress reporting**: Removed incorrect `ZipArchive::close()` + `open()`
  flush loop (files added after the first 500 could be silently skipped). Progress
  is now updated every 100 files without closing/reopening the archive.
- **exec() guard**: `_run_db_export()` now checks `exec()` availability at the start
  and throws a clear error if it is disabled, rather than silently producing an empty
  dump file.
- **Time limits**: Both shutdown functions now allow 600 s (10 min) instead of 300 s
  to accommodate large sites on slower Azure App Service tiers.

## [1.2.4] - 2026-03-22

### Fixed
- Media upload job stuck at 0% on Azure App Service: applied the same
  `register_shutdown_function` + `fastcgi_finish_request()` fix to the
  `upload_media` endpoint that was applied to `export_db` in v1.2.3.
  The unreliable loopback `wp_remote_get('/?doing_wp_cron')` is now
  removed from both export stages.

## [1.2.3] - 2026-03-22

### Fixed
- DB export job stuck at 0% on Azure App Service: replaced unreliable loopback
  `wp_remote_get( '/?doing_wp_cron' )` (0.01 s timeout — too short for an Azure Front
  Door round-trip) with a `register_shutdown_function` + `fastcgi_finish_request()`
  approach. `process_queue()` now runs directly in the same PHP-FPM worker immediately
  after the 202 response is flushed, with no WP-Cron or loopback HTTP dependency.
  WP-Cron scheduling is retained as a belt-and-suspenders fallback.

## [1.2.2] - 2026-03-22

### Added
- Admin notice if plugin is installed to a wrong-named folder (e.g. `kloudstack-migration-3/`).
  Clearly instructs the user to deactivate, delete, and reinstall the ZIP clean.

## [1.2.1] - 2026-03-22

### Fixed
- `KLOUDSTACK_MIGRATION_VERSION` constant corrected to match plugin header version (was `1.0.0`, now `1.2.1`)

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
