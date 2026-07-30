# Activity Monitor — project notes

Custom WordPress plugin: audit logging, with alerts, digests, and export.
Being built toward a wordpress.org release.

**The repo root is the plugin root.** `.github/workflows/claude.yml` and
`.gitignore` also live here and are *not* part of the distributable plugin —
never sync by clearing the tree and dropping in a plugin folder, or the CI
workflow disappears.

## Conventions

- **Versioning.** Plain patch increments, no prerelease tags. Any change to
  package contents bumps the version in *three* places: the header comment in
  `activity-monitor.php`, the `AM_VERSION` constant just below it, and
  `Stable tag:` in `readme.txt`. They must agree.
- **PHP 7.4 is the floor** (`Requires PHP: 7.4`). No `match`, `?->`,
  `str_contains()`, named arguments, enums, or constructor promotion. Linting
  with a modern binary will not catch these — see Verifying below.
- **`<code>` is only for actual code** (HTML, JS, SQL). Never for data values —
  IPs, URLs, slugs, IDs, hashes all render as plain text. There is deliberately
  no CSS override of core's grey `<code>` background; real code wants it.
- **Anywhere a WordPress user is shown, display only `display_name` and
  `user_login`** — stacked as `display_name` bold with `user_login` beneath in
  a `small.am-role`, or `"display_name (user_login)"` inline in single-line
  contexts (Slack/email). Never first/last name (`AM_Admin::real_name()` was
  removed — those fields are frequently blank). `display_name` is read live
  via `get_userdata()`/`WP_User` where the user still exists, or from the
  `am_events.user_display_name` snapshot column otherwise (populated
  alongside `user_login` at write time in `AM_Event_Writer::log()`, and
  backfilled for migrated v1.x rows in `AM_Schema::migrate_legacy_row()`).
  Rows written before that column existed default to `''`; always fall back
  to `user_login` when it's empty.
- **Timestamps** go through `AM_Date_Format::combined()`. CSV/JSON export
  deliberately bypasses it, keeping raw UTC to stay machine-readable.
- **Build on wp-admin's own furniture, don't restyle it.** Screens use core's
  `.wrap` + `.wp-heading-inline` + `.wp-header-end`, `.subsubsub` for status
  filters, `.search-box`, `.tablenav` (`.alignleft.actions` left,
  `.tablenav-pages` right, `<br class="clear">`), `.wp-list-table widefat
  striped`, and core's `.postbox` framing for settings sections. `admin.css`
  should only define what core has no equivalent for — severity/initiator
  badges, the modal, column widths, and `.page-numbers` (which core's list
  tables don't emit, so core ships no styling for it). Before 2.3.0 this file
  restyled buttons, inputs, form-tables, table headers and wrapped every
  screen in a white panel; the result read as a separate product embedded in
  wp-admin. Don't reintroduce that.
- **Dead code gets deleted, not commented out or marked unused.**

## Architecture

Core: `AM_Schema`, `AM_Event_Writer`, `AM_Event_Query`, `AM_Log_Levels`
(8 PSR-3 levels), `AM_Initiator_Detector`, `AM_Logger_Manager` plus one
`AM_Logger_*` per domain, `AM_Event_Labels`, `AM_Date_Format`.

Admin screens: two submenu pages under one top-level menu — Activity Log
(`activity-monitor`, the default) and Settings (`activity-monitor-settings`).
The tabbed single page they replaced went in 2.2.1; `am_tab` no longer means
anything and old links carrying it just land on the log.

Session management (the Active Sessions screen, per-session revoke, the
concurrent-session limit, Revoke Expired, Emergency Lockdown, and `AM_Sessions`
itself) was removed in 2.4.0. Sessions live in WordPress's own `session_tokens`
user meta, which this plugin never owned — so the cleanup drops only the
`am_session_concurrent_limit` option and **must never touch `session_tokens`**,
which would log out every user on the site. The `session.*` entries in
`AM_Event_Labels` are deliberately kept: upgraded sites still have those rows
in `am_events` and they have to keep rendering.

**The log keeps the bare `activity-monitor` slug** because the digest email and
plain-text alert both link to it. Don't rename it.

`AM_Admin::$screen_hooks` collects the return values of `add_menu_page()` /
`add_submenu_page()`, and both `enqueue_assets()` and `show_notices()` test
membership against it. Never hardcode a hook string like
`toplevel_page_activity-monitor` — WordPress builds a submenu's hook from the
sanitized *parent menu slug*, so a hand-written literal can be wrong in a way
that fails silently: assets simply never enqueue on that screen.

Each screen renders through `render_page_*()` → `render_screen_open()` → its
`render_*_screen()` body → `render_screen_close()`. The close helper emits the
shared modal overlay, which both screens need — Settings opens modals into it
too.

All modals share that one overlay, the `openModal()` JS helper, and the
`am_ajax` nonce.

**Cleaning up after removed features** goes in `am_run_upgrade_cleanup()`
(`activity-monitor.php`), which drops tables, options, and cron events left by
features that no longer exist. It's keyed on a stored `am_cleanup_version`, not
a boolean per removal: add a block guarded by `version_compare( $done, '<x.y.z>', '<' )`
for the version that dropped the feature. **Every step must be idempotent** —
the 2.2.2 switch from the old `am_traffic_cleanup_done` boolean re-runs the
2.2.0 block once on sites that already ran it. `uninstall.php` repeats the
drops defensively, since it can't assume any upgrade path ever ran.

Page traffic was removed in 2.2.0 — `AM_Traffic*`, the Traffic tab, and the
`am_traffic_log`/`am_traffic_daily` tables are all gone. Don't reintroduce page-view capture as a parallel
subsystem — if the forensic value is ever wanted back, the audit-relevant
subset (404 storms, `wp-login.php`/`xmlrpc.php` probing, anonymous hits on
restricted paths) belongs in a logger writing through `AM_Event_Writer`, where
it inherits the existing filters, grouping, export, and digest.

`AM_Event_Writer` collapses repeat events within a 5-minute window keyed on
`event_type` + `action` + `object_id` + `initiator`. Loggers with no meaningful
object id (file-editor, fatal-errors) pass `'group' => false`.

## Decisions worth not re-litigating

- **`AM_Date_Format` presets store separate date and time halves**, not one
  combined string, and `combined()` joins them rather than the reverse. Both
  callers that needed a half on its own are gone (the two-line Date column in
  2.0.70, the live traffic feed in 2.2.0), so nothing splits them today — but a
  combined string can't be split back reliably, so the pair stays the source of
  truth. Don't "simplify" it into one string.
- **The Type filter's combined value uses a pipe** (`media|uploaded`), not a
  dot — `event_type` can itself contain a dot on migrated v1 rows
  (`post.delete`), so a dot separator would misread a stored slug as a
  type/action pair. `sanitize_key()` strips both dots and pipes, hence
  `AM_Admin::sanitize_type_filter()`.
- **The user profile modal keys on `user_id`, not the stored login** — logins
  can be renamed and reused, so a login lookup could show the wrong person.

## Legacy v1.x data (a recurring source of surprises)

`AM_Schema::migrate_legacy_row()` copies v1's `event_type` **verbatim** and sets
`action` to `''`, because v1 folded the action into a single slug. An upgraded
site therefore has a *mixed* `event_type` column: clean v2 types (`plugin`),
legacy undelimited slugs (`pluginupdate`), and legacy dotted ones
(`post.delete`).

`AM_Event_Labels` handles all of it: `MAP` (type.action pairs), `TYPE_MAP` (v2
types), `LEGACY_MAP` (exact v1 slugs mapped to their v2 phrasing, so `authlogin`
and `user.login` both read "User Logged In"), `LEGACY_TYPE_MAP` (v1-only types
`auth` and `option`), and `LEGACY_ACTIONS` + `split_on_action()` as a general
fallback that splits an unmapped slug on a trailing action word. Guards: action
≥4 chars, type half ≥3, matching sorted longest-first at runtime.

If labels look wrong, re-run this audit: parse every `$this->log()` /
`AM_Event_Writer::log()` call under `includes/loggers/` and compare the emitted
`event_type.action` pairs against `MAP`, and the types against `TYPE_MAP`. Last
run: 47 pairs, all mapped. The Activity Log's Type dropdown is also the complete
list of distinct `event_type` values in the database.

## Known issues

None currently tracked. Both long-standing entries were resolved in 2.2.1: the
user filter now renders a removable chip in the filter bar (it's still set only
from the profile modal, never from a visible input, which is why the chip
matters), and Settings → Activity Log → Event Sources writes the
`am_disabled_loggers` option that `AM_Logger_Base::is_enabled()` has always
read. That option stores the *disabled* slugs, not the enabled ones, so a
logger added later is on by default without a migration — keep it that way.

## Verifying changes

There is no test suite. Before committing:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l    # syntax
composer install                                      # once, pulls phpcs + standards into vendor/
vendor/bin/phpcs .                                     # escaping, nonces, prepared statements, 7.4 floor
```

`phpcs` auto-discovers `phpcs.xml.dist` at the repo root — no `--standard` flag
needed. That ruleset runs `WordPress-Extra` (security/correctness) plus
`PHPCompatibilityWP` (the check that enforces the 7.4 floor; `php -l` against
a modern binary will happily accept syntax that fatals on 7.4) in one pass,
with formatting/doc-block sniffs excluded — this codebase doesn't conform to
WPCS's structured doc-block or whitespace style, and that's a style choice,
not a defect. If a genuinely new `phpcs.xml.dist` finding is a plugin-constant
table name interpolated into SQL text (not a placeholder), that's accepted
project-wide; suppress it inline with
`// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.`
rather than touching the ruleset, matching every other occurrence in
`includes/`.

Most sites run with `WP_DEBUG` off, which hides undefined-array-key warnings; a
`display_name` key was once read but never built in a render loop, and went
unnoticed for a long time before a debug-enabled environment surfaced it. When
touching a render loop, check every key read against what the builder actually
creates.
