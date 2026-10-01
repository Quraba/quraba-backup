# Upgrading safely

1. Preserve the application UUID, environment, B2 prefix, archive password, Restic password file and
   the package's private restore journals. Copy recovery secrets outside the host before upgrading.
2. Update the package with Composer, review the published config against new defaults, and run the package
   migrations with `php artisan migrate`. The package does not rewrite the repository identity or existing
   manifests, initialize Restic, or change retention as part of installation or upgrade.
3. Run `php artisan quraba:backup:doctor`, `php artisan quraba:backup:restic:health` and
   `php artisan quraba:backup:health`. Resolve every failure before scheduling new backups.
4. Review `database.event_policy`. The default `auto` now includes scheduled events when a direct
   `SHOW GRANTS` statement proves EVENT or ALL privilege on the application database. If it cannot prove
   that privilege, backups continue but explicitly record incomplete database object protection. Use
   `required` to refuse backups without proven EVENT capability, or `assume_none` only after an operator
   decision. Other object privileges are assessed separately by the doctor and archive metadata.
5. Run a new Recovery Point and a dry restore of its exact UUID. Keep the old backups until this is proven.

Archive metadata, remote manifests, retention component records and restore journals remain schema
version 1. Unknown future manifest or journal versions are rejected for destructive operations. A
retained restore workspace is never removed by age-based cleanup; reconcile its exact restore UUID first,
then use `quraba:backup:workspace:cleanup --restore=UUID --execute`.
