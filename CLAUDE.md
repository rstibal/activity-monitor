# Activity Monitor — project notes

Custom WordPress plugin: audit logging, session management, and self-hosted
page traffic. Being built toward a wordpress.org release.

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
- **"User" always means `user_login`.** "Name" means the real first + last name
  from user meta via `AM_Admin::real_name()`, never WordPress's `display_name`
  (that's the nickname field and is often just a copy of the login).
- **Timestamps** go through `AM_Date_Format::combined()` or `time_format()`.
  Chart axis labels (`'M j'`), the peak-hour KPI (`'g A'`), and CSV/JSON export
  deliberately bypass it — the first two are sized to their axis, the third
  keeps raw UTC to stay machine-readable.
- **Dead code gets deleted, not commented out or marked unused.**

## Architecture

Core: `AM_Schema`, `AM_Event_Writer`, `AM_Event_Query`, `AM_Log_Levels`
(8 PSR-3 levels), `AM_Initiator_Detector`, `AM_Logger_Manager` plus one
`AM_Logger_*` per domain, `AM_Sessions`, `AM_Event_Labels`, `AM_Date_Format`.

Traffic (separate schema and retention): `AM_Traffic_Schema` (`am_traffic_log`
raw + `am_traffic_daily` rollup), `AM_Traffic` (capture at `template_redirect`,
bot-filtered), `AM_Traffic_Rollup` (cron, only ever processes *yesterday*, at
3am UTC), `AM_Traffic_Query`.

Admin tabs: Dashboard, Activity Log, Traffic, Active Sessions, Settings.
All modals share one overlay, the `openModal()` JS helper, and the `am_ajax`
nonce.

`AM_Event_Writer` collapses repeat events within a 5-minute window keyed on
`event_type` + `action` + `object_id` + `initiator`. Loggers with no meaningful
object id (file-editor, fatal-errors) pass `'group' => false`.

## Decisions worth not re-litigating

- **Log levels are an ordinal severity *ramp*** — adjacent levels deliberately
  resemble each other. That suits badges, where text carries identity and colour
  only sets mood. It fights a pie chart, where colour is the sole identifier.
  Hence Daily activity is stacked columns: the x-axis carries time and the stack
  carries severity order, so the ramp reinforces position instead of competing
  with it. Three palette tiers were tried as pie fills before this was
  understood.
- **Pie charts need nominal, low-cardinality, reasonably balanced data.**
  Traffic source qualifies, so `--am-pie-src-*` is deliberately *categorical* —
  maximum hue separation, kept clear of the severity palette's greens, oranges
  and reds so the two charts on one screen don't imply a relationship, and clear
  of red so no traffic source reads as an error.
- **`AM_Date_Format` presets store separate date and time halves**, not one
  combined string, because the live traffic feed needs time-only. A combined
  string can't be split back reliably, so the pair is the source of truth and
  `combined()` joins them.
- **The Type filter's combined value uses a pipe** (`media|uploaded`), not a
  dot — `event_type` can itself contain a dot on migrated v1 rows
  (`post.delete`), so a dot separator would misread a stored slug as a
  type/action pair. `sanitize_key()` strips both dots and pipes, hence
  `AM_Admin::sanitize_type_filter()`.
- **The user profile modal keys on `user_id`, not the stored login** — logins
  can be renamed and reused, so a login lookup could show the wrong person.
- **CSS gotcha, hit twice:** a flex-sized item's auto height does not reliably
  resolve percentage heights on its children. `.am-stack-col-area` therefore
  uses an explicit `height: calc(100% - 18px)`, where 18px is
  `.am-stack-label`'s pinned height — keep the two in sync.

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

1. **Timezone handling is inconsistent** and is the highest-value fix. Some call
   sites do `strtotime( $row->date )` with no `' UTC'` suffix (Activity Log
   table, Recent notable events) while others append it (page view modal).
   Dates are stored UTC, so the unsuffixed ones are read as server-local and can
   display shifted times. Decide between appending UTC everywhere or normalising
   at read time, then apply it uniformly.
2. Stacked chart day labels are `nowrap` and centred under ~6px columns, so at
   30 days on a phone they overlap into a smear. Usual fix is thinning labels at
   narrow widths.
3. No on-screen indicator when the user filter is active — the visible User
   search box was removed, but `am_user` still filters (the profile modal links
   with it) and only the Clear filters button hints that it's on.
4. `--am-pie-*` is a misleading name now that the severity palette feeds a
   stacked column chart rather than a pie. `--am-level-*` would be honest.
5. `.am-pie-solo` in `admin.css` is genuinely unused.
6. No admin UI to toggle individual loggers, though `AM_Logger_Manager` supports
   it via the `am_disabled_loggers` option.

## Verifying changes

There is no test suite. Before committing:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l    # syntax
phpcs --standard=WordPress .                          # escaping, nonces, prepared statements
phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 7.4- .
```

The PHPCompatibility run is the one that enforces the 7.4 floor — `php -l`
against a modern binary will happily accept syntax that fatals on 7.4.

Most sites run with `WP_DEBUG` off, which hides undefined-array-key warnings; a
`display_name` key was read but never built in the sessions table for a long
time before a debug-enabled environment surfaced it. When touching a render
loop, check every key read against what the builder actually creates.
