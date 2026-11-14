-- Jadwal Interchange — Supabase schema
-- Run this in Supabase SQL Editor (Project -> SQL Editor -> New query).
--
-- Design note: the PHP backend talks to Supabase using the SERVICE ROLE key only
-- (never the anon key, and the browser never talks to Supabase directly). All
-- authorization (who can see/edit what) is enforced in PHP using the session
-- after Supabase Auth login. RLS is still enabled on every table, default-deny,
-- as defense in depth in case a key ever leaks or a client-side call is added later.

create extension if not exists "pgcrypto";

-- ---------------------------------------------------------------------------
-- Enums
-- ---------------------------------------------------------------------------
create type user_role as enum ('teacher','admin');
create type day_of_week as enum ('mon','tue','wed','thu','fri','sat');
create type release_status as enum ('pending','open','rejected','closed');
create type take_status as enum ('pending','approved','rejected');

-- ---------------------------------------------------------------------------
-- profiles — one row per Supabase auth user (teacher or admin)
-- ---------------------------------------------------------------------------
create table profiles (
  id uuid primary key references auth.users(id) on delete cascade,
  full_name text not null,
  email text not null unique,
  phone text,
  role user_role not null default 'teacher',
  created_at timestamptz not null default now()
);

-- ---------------------------------------------------------------------------
-- period_slots — master list of periods & their times, from Blank_Jadwal.pdf.
-- Saturday runs a different time grid than Mon-Fri, hence the two columns.
-- ---------------------------------------------------------------------------
create table period_slots (
  id serial primary key,
  period_label text not null,          -- e.g. 'Period 2 (PT)', 'Period 11 (O)'
  sort_order int not null,             -- left-to-right column order on the Jadwal
  start_time_weekday time not null,
  end_time_weekday time not null,
  start_time_saturday time,
  end_time_saturday time
);

-- ---------------------------------------------------------------------------
-- classes — e.g. '1 A F', '2 DARS M'
-- ---------------------------------------------------------------------------
create table classes (
  id serial primary key,
  name text not null unique
);

-- ---------------------------------------------------------------------------
-- timetable_entries — one row per (week, class, day, period) assignment
-- ---------------------------------------------------------------------------
create table timetable_entries (
  id uuid primary key default gen_random_uuid(),
  week_number int not null,
  class_id int not null references classes(id),
  day day_of_week not null,
  period_slot_id int not null references period_slots(id),
  subject text,
  book text,
  teacher_id uuid references profiles(id),
  -- Raw teacher identity from an imported Jadwal, kept until a real login
  -- account exists to resolve teacher_id (source spreadsheets commonly have
  -- no teacher email addresses at all).
  teacher_name text,
  teacher_phone text,
  entry_date date,
  created_at timestamptz not null default now(),
  -- teacher_id is part of the key (not just week/class/day/period) because a
  -- slot can legitimately be co-taught by two teachers at once; Postgres
  -- treats distinct NULLs as non-conflicting, so raw-imported rows (teacher_id
  -- still null) never block each other here.
  unique (week_number, class_id, day, period_slot_id, teacher_id)
);

create index idx_timetable_entries_teacher on timetable_entries(teacher_id, week_number);

-- ---------------------------------------------------------------------------
-- release_requests — a teacher asking to give up one of their periods
-- ---------------------------------------------------------------------------
create table release_requests (
  id uuid primary key default gen_random_uuid(),
  entry_id uuid not null references timetable_entries(id) on delete cascade,
  teacher_id uuid not null references profiles(id),
  reason text not null default 'Nisaab tamam',
  status release_status not null default 'pending',
  created_at timestamptz not null default now(),
  decided_by uuid references profiles(id),
  decided_at timestamptz
);

-- Only one active (pending/open) release per timetable entry at a time.
create unique index one_active_release_per_entry on release_requests(entry_id)
  where status in ('pending','open');

create index idx_release_requests_status on release_requests(status);

-- ---------------------------------------------------------------------------
-- take_requests — another teacher asking to take an open released period
-- ---------------------------------------------------------------------------
create table take_requests (
  id uuid primary key default gen_random_uuid(),
  release_request_id uuid not null references release_requests(id) on delete cascade,
  requesting_teacher_id uuid not null references profiles(id),
  status take_status not null default 'pending',
  created_at timestamptz not null default now(),
  decided_by uuid references profiles(id),
  decided_at timestamptz
);

-- Only one pending take-request per teacher per release at a time.
create unique index one_pending_take_per_release_per_teacher
  on take_requests(release_request_id, requesting_teacher_id)
  where status = 'pending';

create index idx_take_requests_status on take_requests(status);

-- ---------------------------------------------------------------------------
-- email_log — record of approval emails sent out
-- ---------------------------------------------------------------------------
create table email_log (
  id uuid primary key default gen_random_uuid(),
  take_request_id uuid references take_requests(id),
  recipient_email text not null,
  subject text not null,
  body text not null,
  sent_by uuid references profiles(id),
  sent_at timestamptz not null default now()
);

-- ---------------------------------------------------------------------------
-- RLS — default deny on every table (PHP backend uses the service_role key,
-- which bypasses RLS entirely; these policies only matter if anon/authenticated
-- keys are ever used directly against the API).
-- ---------------------------------------------------------------------------
alter table profiles enable row level security;
alter table period_slots enable row level security;
alter table classes enable row level security;
alter table timetable_entries enable row level security;
alter table release_requests enable row level security;
alter table take_requests enable row level security;
alter table email_log enable row level security;

create policy "profiles_self_select" on profiles
  for select using (auth.uid() = id);
