-- Migration 0003 — its_id as the shared key between `profiles` (user) and
-- `timetable_entries` (jadwal), per explicit instruction: "itsid is key in
-- both user and jadwal".
--
-- ITS ID is the unique member ID already embedded at the front of every
-- "Mufawwaz/Department" cell in the source Jadwal (e.g. "20359261 Rashida
-- bai ..."). It is the only reliable identifier for a teacher in the raw
-- import, since the source spreadsheet has no email addresses at all.
--
-- How this gets used: a teacher's login (profiles row) gets its_id set
-- either by an admin ahead of time, or automatically the first time they
-- authenticate (see src/Auth.php). The moment profiles.its_id is known, the
-- app "claims" every timetable_entries row with a matching teacher_its_id
-- and teacher_id still null by setting teacher_id to that profile's id —
-- after that one-time claim, all existing dashboard/release/take logic
-- (which already filters by teacher_id) works unchanged.
--
-- Idempotent — safe to run more than once.

alter table profiles add column if not exists its_id text;
create unique index if not exists profiles_its_id_key on profiles(its_id) where its_id is not null;

alter table timetable_entries add column if not exists teacher_its_id text;
create index if not exists idx_timetable_entries_teacher_its_id on timetable_entries(teacher_its_id);
