# Changelog

All notable changes to `alfasix/alfakit-ops` are documented in this file.

## Unreleased

- Initial release: an **Operations** cluster with an overview (system information and queue, failed job, scheduler and maintenance cards), failed jobs (filters, redacted payload viewer, retry / retry selected / retry all, forget, flush), queues (pending, delayed and reserved jobs per queue for the database and Redis drivers, clear), job batches (progress, failures, retry failed jobs, cancel), the scheduler (human-readable frequencies, next due time in the panel timezone, last run, "Run now" with captured output), a log viewer (Monolog parsing, level filter, search, reverse paging that never loads whole files, download, delete, path traversal protection) and cache & maintenance tasks (cache, config, route, view, event and compiled caches, `optimize` / `optimize:clear`, AlfaKit discovery cache, maintenance mode with a secret bypass URL). Deny-by-default authorisation (`authorize()` or the `viewAlfakitOps` gate), per-ability action authorisation, read-only mode, per-feature toggles and tenant panel opt-in.
