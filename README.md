# Jadwal Interchange

A mobile-first PHP web app for teachers to hand periods back and forth on a
weekly timetable ("Jadwal"): release a period you can't cover, pick up an
open period in another class, with admin approval in between.

Pure PHP 8, no Composer/third-party packages. Data and auth both live in
Supabase (Postgres + GoTrue), accessed from PHP only via native cURL.

## 1. Set up Supabase

1. Create a new project at https://supabase.com.
2. Open **SQL Editor** and run, in order:
   1. `sql/schema.sql` — tables, enums, indexes, RLS (authoritative schema,
      do not edit).
   2. `sql/seed_dummy.sql` — a few fictional classes/periods/timetable rows
      to develop against. `teacher_id` is left `NULL` on every seeded row —
      see the comment at the top of that file for how to assign one after
      you've created a login (step 3 below).
3. Go to **Project Settings -> API** and copy the **Project URL**, the
   **anon public** key, and the **service_role** key (keep the service key
   secret — it bypasses Row Level Security).

## 2. Configure the app

```
cp .env.example .env
```

Edit `.env` and fill in:

```
SUPABASE_URL=https://your-project-ref.supabase.co
SUPABASE_ANON_KEY=...
SUPABASE_SERVICE_KEY=...
MAIL_FROM_ADDRESS=no-reply@yourschool.example
MAIL_FROM_NAME=Jadwal Interchange
```

`.env` is gitignored — never commit real keys.

## 3. Create a login

There's no public sign-up screen (teachers are provisioned by an admin).
Use the CLI script, which creates a Supabase Auth user and the matching
`profiles` row in one step:

```
php scripts/create_user.php teacher1@example.com "a-strong-password" "Jane Teacher" teacher
php scripts/create_user.php admin@example.com "a-strong-password" "Admin Person" admin
```

If you want the dummy timetable rows from `sql/seed_dummy.sql` to actually
show up on a dashboard, update one or two of them to point `teacher_id` at
the new teacher's id (printed by the script), e.g. in the Supabase SQL
editor:

```sql
update timetable_entries
set teacher_id = '<uuid printed by create_user.php>'
where day = 'mon' and week_number = 36;
```

## 4. Run it

No build step. Serve the `public/` directory directly:

```
php -S localhost:8000 -t public
```

Open http://localhost:8000 — it redirects to the login page, then to the
dashboard once you sign in.

## How it works

- **Dashboard** (`public/dashboard.php`) — your own timetable for the
  current week, Monday-Saturday, with a day-chip switcher (periods, times,
  class, subject, book per day).
- **Release Period** (`public/release.php`) — lists your own periods for
  the current week. Tapping one opens a small modal with a reason
  pre-filled as "Nisaab tamam" (editable) and a Send button, which creates
  a `release_requests` row (`status = 'pending'`).
- **Take Period** (`public/take.php`) — pick a class from the dropdown to
  see all currently **open** released periods for that class. Tap "Allot to
  me" on one or several (across different classes — selections are kept in
  your session as you switch classes), then "Send for approval" to create a
  `take_requests` row for each one.
- **Admin** (`public/admin/index.php`, visible only to `profiles.role =
  'admin'`) — a queue of pending `release_requests` and `take_requests`.
  - Approving a release request sets it to `open` (now visible in Take
    Period for everyone else).
  - Approving a take request does the actual handover in one go: marks the
    take request `approved`, closes the release request, and reassigns
    `timetable_entries.teacher_id` to the requesting teacher. Any other
    still-pending take requests on the same release are auto-rejected since
    the period has already been given away. You're then sent to
    `public/admin/compose_email.php`, a pre-filled subject/body (teacher
    name, class, day, period, subject) you can edit before sending — sending
    logs a row in `email_log`.

Every page/endpoint re-checks `$_SESSION['role']` / `$_SESSION['user_id']`
server-side before touching data (e.g. a teacher can only release their own
`timetable_entries`; only an admin can hit any `admin_*` endpoint) — the
Supabase **service_role** key used by `src/SupabaseClient.php` bypasses Row
Level Security entirely, so this PHP-side check is the only thing actually
protecting the data. All state-changing requests also require a session
CSRF token (see `csrf_field()` / `require_csrf()` in `src/helpers.php`).

"Current week" has no separate calendar table — it's simply the highest
`week_number` present in `timetable_entries` (see `current_week_number()` in
`src/helpers.php`). Whoever imports a week's Jadwal just inserts it with the
next `week_number`.

## Known limitation: email sending

`src/Mailer.php` currently sends mail with PHP's built-in `mail()` function,
which depends on a locally configured MTA/sendmail and has no delivery
guarantees, retries, or TLS/auth — this was an explicit, deliberate decision
to keep the stack dependency-free for now, not an oversight. To switch to
real SMTP later, you only need to replace the internals of
`Mailer::send(string $to, string $subject, string $body): bool` — every
caller in the app already uses that same method signature.

## File layout

```
public/            entry points (served by `php -S ... -t public`)
  api/              small JSON/form endpoints, one per action
  admin/            admin-only pages
src/                shared PHP classes/helpers (not web-accessible)
assets/             hand-written css/js, no CDN dependency
scripts/            CLI-only tooling (create_user.php)
sql/                schema.sql (authoritative, untouched) + seed_dummy.sql
```
