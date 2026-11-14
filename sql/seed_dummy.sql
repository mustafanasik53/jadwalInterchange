-- Jadwal Interchange — dummy/sample data for local development.
--
-- Run this AFTER sql/schema.sql in the Supabase SQL editor.
--
-- Everything in this file is FICTIONAL: made-up class names, made-up period
-- times, made-up subjects. None of it is copied from Blank_Jadwal.pdf or
-- Week_35_Jadwal.xlsx (those files contain real people's names/phone numbers
-- and were never opened for data — they were only used, by eye, as a layout
-- reference for "days Mon-Sat as rows, periods 2-11 as columns").
--
-- IMPORTANT — teacher_id is left NULL on every seeded timetable_entries row.
-- To actually log in and see a timetable:
--   1. Run:  php scripts/create_user.php you@example.com "somepassword" "Your Name" teacher
--      (this creates a Supabase Auth user + a matching profiles row)
--   2. Then update one or more timetable_entries rows to point teacher_id at
--      that new profiles.id, e.g.:
--        update timetable_entries
--        set teacher_id = '<uuid from step 1>'
--        where day = 'mon' and week_number = 36;
--   3. Create a second teacher the same way if you want to test the
--      Take Period flow (one teacher releases a period, another takes it).
--   4. Create an admin the same way (role argument = 'admin') to test the
--      approval queue and compose-email screen.

-- ---------------------------------------------------------------------------
-- period_slots — 10 placeholder periods (Period 2 through Period 11, matching
-- the column shape of the Jadwal). Times below are illustrative placeholders
-- only; adjust them in the Supabase table editor to match your school's real
-- bell schedule.
-- ---------------------------------------------------------------------------
insert into period_slots
  (period_label, sort_order, start_time_weekday, end_time_weekday, start_time_saturday, end_time_saturday)
values
  ('Period 2',  1, '08:00', '08:40', '08:00', '08:35'),
  ('Period 3',  2, '08:40', '09:20', '08:35', '09:10'),
  ('Period 4',  3, '09:20', '10:00', '09:10', '09:45'),
  ('Period 5',  4, '10:00', '10:40', '09:45', '10:20'),
  ('Period 6',  5, '10:55', '11:35', '10:30', '11:05'),
  ('Period 7',  6, '11:35', '12:15', '11:05', '11:40'),
  ('Period 8',  7, '12:15', '12:55', '11:40', '12:15'),
  ('Period 9',  8, '13:30', '14:10', null, null),
  ('Period 10', 9, '14:10', '14:50', null, null),
  ('Period 11', 10, '14:50', '15:30', null, null);

-- ---------------------------------------------------------------------------
-- classes — a handful of fictional class names.
-- ---------------------------------------------------------------------------
insert into classes (name) values
  ('1 A F'),
  ('2 B M'),
  ('3 C F'),
  ('4 D M');

-- ---------------------------------------------------------------------------
-- timetable_entries — a few dummy rows for week 36, teacher_id left NULL.
-- (See the instructions at the top of this file for how to assign a teacher.)
-- ---------------------------------------------------------------------------
insert into timetable_entries (week_number, class_id, day, period_slot_id, subject, book, teacher_id, entry_date)
select 36, c.id, 'mon', p.id, 'Mathematics', 'Primary Maths Book 4', null, date '2026-09-07'
from classes c, period_slots p
where c.name = '1 A F' and p.sort_order = 1;

insert into timetable_entries (week_number, class_id, day, period_slot_id, subject, book, teacher_id, entry_date)
select 36, c.id, 'mon', p.id, 'English', 'English Reader 2', null, date '2026-09-07'
from classes c, period_slots p
where c.name = '2 B M' and p.sort_order = 2;

insert into timetable_entries (week_number, class_id, day, period_slot_id, subject, book, teacher_id, entry_date)
select 36, c.id, 'tue', p.id, 'Science', 'General Science 3', null, date '2026-09-08'
from classes c, period_slots p
where c.name = '3 C F' and p.sort_order = 1;

insert into timetable_entries (week_number, class_id, day, period_slot_id, subject, book, teacher_id, entry_date)
select 36, c.id, 'sat', p.id, 'Islamiyat', 'Islamiyat Book 1', null, date '2026-09-12'
from classes c, period_slots p
where c.name = '4 D M' and p.sort_order = 3;
