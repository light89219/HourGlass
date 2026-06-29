#!/usr/bin/env python3
"""
HourGlass CLI Schedule Generator

Reads an input text file and auto-generates a weekly schedule,
using the same algorithm as the HourGlass web app (pages/generate.php).

Usage:
    python generate_schedule.py input.txt
"""

import argparse
import math
import sys
from collections import defaultdict
from datetime import datetime, timedelta


DAY_ABBR = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"]


def parse_input(filepath):
    with open(filepath, "r") as f:
        lines = f.readlines()

    section = None
    data = {
        "settings": {},
        "teachers": [],
        "unavailability": {},
        "courses": [],
        "categories": [],
        "assignments": [],
        "groups": [],
    }

    for raw_line in lines:
        line = raw_line.strip()
        if not line or line.startswith("#"):
            continue

        if line.startswith("[") and line.endswith("]"):
            section = line[1:-1].strip().lower()
            continue

        if section == "settings":
            if "=" in line:
                key, val = line.split("=", 1)
                data["settings"][key.strip()] = val.strip()

        elif section == "teachers":
            data["teachers"].append(line)

        elif section == "unavailability":
            if ":" in line:
                teacher, slots_str = line.split(":", 1)
                teacher = teacher.strip()
                slots = []
                for s in slots_str.split(","):
                    s = s.strip()
                    if "/" in s:
                        parts = s.split("/")
                        slots.append((int(parts[0]), int(parts[1])))
                data["unavailability"][teacher] = slots

        elif section == "courses":
            parts = [p.strip() for p in line.split("|")]
            course = {"name": parts[0], "sub_start_date": None, "sub_end_date": None}
            if len(parts) >= 2 and parts[1]:
                course["sub_start_date"] = parts[1]
            if len(parts) >= 3 and parts[2]:
                course["sub_end_date"] = parts[2]
            data["courses"].append(course)

        elif section == "categories":
            data["categories"].append(line)

        elif section == "assignments":
            parts = [p.strip() for p in line.split("|")]
            if len(parts) >= 4:
                data["assignments"].append({
                    "category": parts[0],
                    "course": parts[1],
                    "teacher": parts[2],
                    "lecture_count": int(parts[3]),
                })

        elif section == "groups":
            parts = [p.strip() for p in line.split("|")]
            if len(parts) >= 2:
                data["groups"].append({"name": parts[0], "category": parts[1]})

    return data


def get_teaching_days(weekend_days):
    return [d for d in range(7) if d not in weekend_days]


def get_teaching_weeks(start_date, end_date, weekend_days, holidays, sub_start=None, sub_end=None):
    start = datetime.strptime(sub_start or start_date, "%Y-%m-%d")
    end = datetime.strptime(sub_end or end_date, "%Y-%m-%d")
    teaching_days = 0
    current = start
    while current <= end:
        dow = int(current.strftime("%w"))
        date_str = current.strftime("%Y-%m-%d")
        if dow not in weekend_days and date_str not in holidays:
            teaching_days += 1
        current += timedelta(days=1)
    days_per_week = 7 - len(weekend_days)
    if days_per_week <= 0:
        return 0
    return max(1, math.ceil(teaching_days / days_per_week))


def generate(data):
    settings = data["settings"]
    start_date = settings["start_date"]
    end_date = settings["end_date"]
    daily_slots = int(settings.get("daily_slots", "6"))

    weekend_days = set()
    if "weekends" in settings:
        for d in settings["weekends"].split(","):
            d = d.strip()
            if d:
                weekend_days.add(int(d))

    holidays = set()
    if "holidays" in settings:
        for h in settings["holidays"].split(","):
            h = h.strip()
            if h:
                holidays.add(h)

    teaching_days = get_teaching_days(weekend_days)
    if not teaching_days:
        print("ERROR: All days are marked as weekends.")
        sys.exit(1)

    teachers = {name: idx for idx, name in enumerate(data["teachers"])}
    courses = {}
    for c in data["courses"]:
        courses[c["name"]] = c

    categories = {name: idx for idx, name in enumerate(data["categories"])}

    # Build teacher availability: default all available, then remove unavailable slots
    teacher_avail = defaultdict(lambda: defaultdict(set))
    for tname in data["teachers"]:
        for dow in teaching_days:
            for slot in range(1, daily_slots + 1):
                teacher_avail[tname][dow].add(slot)
    for tname, unavail_slots in data["unavailability"].items():
        for dow, slot in unavail_slots:
            teacher_avail[tname][dow].discard(slot)

    # Build category -> assignments
    cat_courses = defaultdict(list)
    for a in data["assignments"]:
        course_info = courses.get(a["course"])
        if not course_info:
            print(f"WARNING: Course '{a['course']}' in assignments not found in [courses]. Skipping.")
            continue
        if a["teacher"] not in teachers:
            print(f"WARNING: Teacher '{a['teacher']}' in assignments not found in [teachers]. Skipping.")
            continue
        if a["category"] not in categories:
            print(f"WARNING: Category '{a['category']}' in assignments not found in [categories]. Skipping.")
            continue
        cat_courses[a["category"]].append({
            "course_name": a["course"],
            "teacher_name": a["teacher"],
            "lecture_count": a["lecture_count"],
            "sub_start_date": course_info["sub_start_date"],
            "sub_end_date": course_info["sub_end_date"],
        })

    groups = data["groups"]
    if not groups:
        print("ERROR: No groups defined.")
        sys.exit(1)

    # ============================================================
    # STEP 1: Even-distribution allocation
    # Each course gets ceil(lecture_count / teaching_weeks) per week
    # ============================================================
    group_entries = {}
    group_remaining = {}
    group_daily_limit = {}

    for g in groups:
        gname = g["name"]
        cat = g["category"]
        if cat not in cat_courses:
            continue

        group_entries[gname] = []
        group_remaining[gname] = []
        group_daily_limit[gname] = []

        for cc in cat_courses[cat]:
            weeks = get_teaching_weeks(
                start_date, end_date, weekend_days, holidays,
                cc["sub_start_date"], cc["sub_end_date"]
            )
            weekly_slots = math.ceil(cc["lecture_count"] / weeks) if weeks > 0 else cc["lecture_count"]
            weekly_slots = max(1, weekly_slots)

            tname = cc["teacher_name"]
            avail_days = sum(1 for dow in teaching_days if teacher_avail[tname][dow])

            group_entries[gname].append({
                "course_name": cc["course_name"],
                "teacher_name": tname,
                "weekly_needed": weekly_slots,
                "lecture_count": cc["lecture_count"],
            })
            group_remaining[gname].append(weekly_slots)
            group_daily_limit[gname].append(math.ceil(weekly_slots / max(1, avail_days)))

    # ============================================================
    # STEP 2: Build weekly template (round-robin, spread across days)
    # ============================================================
    teacher_assigned = defaultdict(lambda: defaultdict(set))
    scheduled = []
    group_last_course = {}
    group_day_count = defaultdict(lambda: defaultdict(lambda: defaultdict(int)))

    for slot in range(1, daily_slots + 1):
        for dow in teaching_days:
            if slot == 1:
                group_last_course = {}
            for g in groups:
                gname = g["name"]
                if gname not in group_entries:
                    scheduled.append({
                        "group": gname, "day_of_week": dow,
                        "slot": slot, "course": None, "teacher": None,
                    })
                    continue

                last_cname = group_last_course.get(gname)
                best_idx = -1
                best_score = -float("inf")

                # Pass 1: prefer different from previous slot
                for i, rem in enumerate(group_remaining[gname]):
                    if rem <= 0:
                        continue
                    e = group_entries[gname][i]
                    if e["course_name"] == last_cname:
                        continue
                    if slot not in teacher_avail[e["teacher_name"]][dow]:
                        continue
                    if e["teacher_name"] in teacher_assigned[dow][slot]:
                        continue

                    today_count = group_day_count[gname][dow][e["course_name"]]
                    limit = group_daily_limit[gname][i]
                    score = -today_count * 1000
                    if today_count >= limit:
                        score -= 5000
                    score += rem
                    if score > best_score:
                        best_score = score
                        best_idx = i

                # Pass 2: allow consecutive
                if best_idx == -1:
                    for i, rem in enumerate(group_remaining[gname]):
                        if rem <= 0:
                            continue
                        e = group_entries[gname][i]
                        if slot not in teacher_avail[e["teacher_name"]][dow]:
                            continue
                        if e["teacher_name"] in teacher_assigned[dow][slot]:
                            continue

                        today_count = group_day_count[gname][dow][e["course_name"]]
                        limit = group_daily_limit[gname][i]
                        score = -today_count * 1000
                        if today_count >= limit:
                            score -= 5000
                        score += rem
                        if score > best_score:
                            best_score = score
                            best_idx = i

                if best_idx >= 0:
                    e = group_entries[gname][best_idx]
                    scheduled.append({
                        "group": gname, "day_of_week": dow,
                        "slot": slot, "course": e["course_name"], "teacher": e["teacher_name"],
                    })
                    teacher_assigned[dow][slot].add(e["teacher_name"])
                    group_remaining[gname][best_idx] -= 1
                    group_last_course[gname] = e["course_name"]
                    group_day_count[gname][dow][e["course_name"]] += 1
                else:
                    scheduled.append({
                        "group": gname, "day_of_week": dow,
                        "slot": slot, "course": None, "teacher": None,
                    })
                    group_last_course[gname] = None

    # Build weekly template lookup
    weekly_template = defaultdict(lambda: defaultdict(dict))
    for s in scheduled:
        weekly_template[s["group"]][s["day_of_week"]][s["slot"]] = s

    # ============================================================
    # Print warnings for unplaced weekly slots
    # ============================================================
    warnings = []
    for g in groups:
        gname = g["name"]
        if gname not in group_entries:
            continue
        for i, entry in enumerate(group_entries[gname]):
            if group_remaining[gname][i] > 0:
                warnings.append(
                    f"  Group '{gname}': could not place {group_remaining[gname][i]} "
                    f"weekly slot(s) for \"{entry['course_name']}\""
                )

    # ============================================================
    # Print weekly template
    # ============================================================
    print("=" * 72)
    print("  WEEKLY SCHEDULE TEMPLATE")
    print("=" * 72)

    for g in groups:
        gname = g["name"]
        print(f"\n--- {g['category']} / {gname} ---")

        col_width = 22
        header = f"{'Slot':<6}"
        for dow in teaching_days:
            header += f"{DAY_ABBR[dow]:^{col_width}}"
        print(header)
        print("-" * len(header))

        for slot in range(1, daily_slots + 1):
            course_row = f"{slot:<6}"
            teacher_row = f"{'':6}"
            for dow in teaching_days:
                cell = weekly_template[gname][dow].get(slot)
                if cell and cell["course"]:
                    c_label = cell["course"]
                    t_label = cell["teacher"]
                    if len(c_label) > col_width - 2:
                        c_label = c_label[:col_width - 4] + ".."
                    if len(t_label) > col_width - 2:
                        t_label = t_label[:col_width - 4] + ".."
                    course_row += f"{c_label:^{col_width}}"
                    teacher_row += f"{('(' + t_label + ')'):^{col_width}}"
                else:
                    course_row += f"{'--':^{col_width}}"
                    teacher_row += f"{'':^{col_width}}"
            print(course_row)
            print(teacher_row)

    # ============================================================
    # STEP 3: Expand into daily schedule & count actual lectures
    # ============================================================
    course_ranges = {}
    for c in data["courses"]:
        course_ranges[c["name"]] = c

    actual_lectures = defaultdict(lambda: defaultdict(int))
    daily_count = 0

    current = datetime.strptime(start_date, "%Y-%m-%d")
    end_dt = datetime.strptime(end_date, "%Y-%m-%d")

    while current <= end_dt:
        dow = int(current.strftime("%w"))
        date_str = current.strftime("%Y-%m-%d")

        if dow not in weekend_days and date_str not in holidays:
            for g in groups:
                gname = g["name"]
                for slot in range(1, daily_slots + 1):
                    tmpl = weekly_template[gname][dow].get(slot)
                    course_name = tmpl["course"] if tmpl else None
                    teacher_name = tmpl["teacher"] if tmpl else None

                    if course_name and course_name in course_ranges:
                        cr = course_ranges[course_name]
                        if cr["sub_start_date"] and date_str < cr["sub_start_date"]:
                            course_name = None
                            teacher_name = None
                        if cr["sub_end_date"] and date_str > cr["sub_end_date"]:
                            course_name = None
                            teacher_name = None

                    if course_name and teacher_name:
                        actual_lectures[gname][(course_name, teacher_name)] += 1

                    daily_count += 1

        current += timedelta(days=1)

    # ============================================================
    # Print remaining lectures per course per group
    # ============================================================
    print("\n" + "=" * 72)
    print("  REMAINING LECTURES PER COURSE")
    print("=" * 72)

    any_remaining = False
    for g in groups:
        gname = g["name"]
        cat = g["category"]
        if cat not in cat_courses:
            continue

        print(f"\n--- {cat} / {gname} ---")
        print(f"  {'Course':<20} {'Teacher':<20} {'Required':>10} {'Scheduled':>10} {'Remaining':>10}")
        print(f"  {'-'*20} {'-'*20} {'-'*10} {'-'*10} {'-'*10}")

        for cc in cat_courses[cat]:
            required = cc["lecture_count"]
            key = (cc["course_name"], cc["teacher_name"])
            scheduled_count = actual_lectures[gname].get(key, 0)
            remaining = required - scheduled_count
            marker = " <<<" if remaining > 0 else ""
            if remaining > 0:
                any_remaining = True
            print(f"  {cc['course_name']:<20} {cc['teacher_name']:<20} {required:>10} {scheduled_count:>10} {remaining:>10}{marker}")

    # ============================================================
    # Print warnings
    # ============================================================
    if warnings:
        print("\n" + "=" * 72)
        print("  WARNINGS (unplaced weekly slots)")
        print("=" * 72)
        for w in warnings:
            print(w)

    # Summary
    print("\n" + "=" * 72)
    print(f"  Total daily slots created: {daily_count}")
    if any_remaining:
        print("  NOTE: Some courses have remaining lectures (marked with <<<).")
        print("  Consider increasing daily_slots or extending the date range.")
    else:
        print("  All required lectures are fully covered!")
    print("=" * 72)


def main():
    parser = argparse.ArgumentParser(
        description="HourGlass CLI Schedule Generator",
        epilog="See input_example.txt for the input file format.",
    )
    parser.add_argument("input_file", help="Path to the input text file")
    args = parser.parse_args()

    data = parse_input(args.input_file)

    required_settings = ["start_date", "end_date"]
    for key in required_settings:
        if key not in data["settings"]:
            print(f"ERROR: Missing required setting '{key}' in [settings].")
            sys.exit(1)

    if not data["teachers"]:
        print("ERROR: No teachers defined in [teachers].")
        sys.exit(1)
    if not data["courses"]:
        print("ERROR: No courses defined in [courses].")
        sys.exit(1)
    if not data["categories"]:
        print("ERROR: No categories defined in [categories].")
        sys.exit(1)
    if not data["assignments"]:
        print("ERROR: No assignments defined in [assignments].")
        sys.exit(1)
    if not data["groups"]:
        print("ERROR: No groups defined in [groups].")
        sys.exit(1)

    generate(data)

if __name__ == "__main__":
    main()
