-- Migration 0002 — apply to the already-provisioned Supabase project to bring
-- it in line with the current sql/schema.sql (which already reflects this).
--
-- Why: real-world Jadwal data (Week_35_Jadwal.xlsx) showed two things the
-- original schema didn't account for:
--   1. A timetable_entries row sometimes needs to carry a teacher's raw
--      name/phone before a real login account exists for them (the source
--      spreadsheet has zero email addresses, so teacher_id can't be resolved
--      to a profiles row at import time).
--   2. Some (class, day, period) slots are legitimately co-taught by two
--      teachers at once, which the original one-row-per-slot unique
--      constraint didn't allow.
--
-- Idempotent — safe to run more than once.

alter table timetable_entries add column if not exists teacher_name text;
alter table timetable_entries add column if not exists teacher_phone text;

alter table timetable_entries drop constraint if exists timetable_entries_week_number_class_id_day_period_slot_id_key;

do $$
begin
  if not exists (
    select 1 from pg_constraint where conname = 'timetable_entries_week_class_day_period_teacher_key'
  ) then
    alter table timetable_entries
      add constraint timetable_entries_week_class_day_period_teacher_key
      unique (week_number, class_id, day, period_slot_id, teacher_id);
  end if;
end $$;

insert into period_slots (period_label, sort_order, start_time_weekday, end_time_weekday, start_time_saturday, end_time_saturday)
select '(PT)', 0, '06:00', '07:00', '08:15', '08:50'
where not exists (select 1 from period_slots where period_label = '(PT)');
