<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_clientportal;

use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/grade/grade_item.php');
require_once($CFG->libdir . '/grade/grade_grade.php');

/**
 * Learner progress data for one client. Every query is limited to the client's learners and the
 * course enrolments the client owns.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report {
    /** Enrolment status: active. */
    public const ENROL_ACTIVE = 'active';
    /** Enrolment status: suspended or outside its dates. */
    public const ENROL_SUSPENDED = 'suspended';
    /** Enrolment status: no longer enrolled. */
    public const ENROL_REMOVED = 'removed';

    /** Progress status values. */
    public const NOTSTARTED = 'notstarted';
    /** In progress. */
    public const INPROGRESS = 'inprogress';
    /** Completed. */
    public const COMPLETED = 'completed';

    /** @var scope */
    private scope $scope;
    /** @var array courseid => grade_item|null */
    private array $courseitems = [];

    /**
     * Constructor.
     *
     * @param scope $scope
     */
    public function __construct(scope $scope) {
        $this->scope = $scope;
    }

    /**
     * Owned enrolments with progress, for all learners or the ones given.
     *
     * Each row: claimid, userid, courseid, coursename, shortname, enrolled (time), enrolstatus,
     * total (tracked activities), done, percent (or null), completed (time or null), lastaccess,
     * grade (formatted, or '' when none/hidden), status.
     *
     * @param int[]|null $userids
     * @param int|null $groupid only learners in this client group
     * @param int|null $courseid only this course
     * @return stdClass[] keyed by claim id
     */
    public function enrolments(?array $userids = null, ?int $groupid = null, ?int $courseid = null): array {
        global $DB;
        [$ljoin, $lwhere, $params] = $this->scope->learner_sql('u', 'le');
        $params['clientid'] = $this->scope->id();
        $params['now1'] = $params['now2'] = time();
        $extra = '';
        if ($userids !== null) {
            if (!$userids) {
                return [];
            }
            [$in, $inparams] = $DB->get_in_or_equal(array_map('intval', $userids), SQL_PARAMS_NAMED, 'uid');
            $extra .= " AND ce.userid $in";
            $params += $inparams;
        }
        if ($groupid) {
            $extra .= " AND EXISTS (SELECT 1 FROM {local_clientportal_groupmem} gm
                                     WHERE gm.groupid = :groupid AND gm.userid = ce.userid)";
            $params['groupid'] = $groupid;
        }
        if ($courseid) {
            $extra .= " AND ce.courseid = :courseid";
            $params['courseid'] = $courseid;
        }
        $sql = "SELECT ce.id AS claimid, ce.userid, ce.courseid, ce.timecreated AS claimed,
                       c.fullname AS coursename, c.shortname,
                       cc.timecompleted AS completed,
                       la.timeaccess AS lastaccess,
                       ue.firstenrolled, ue.activecount, ue.anycount,
                       gg.finalgrade, gg.hidden AS gradehidden
                  FROM {local_clientportal_enrol} ce
                  JOIN {user} u ON u.id = ce.userid
                  $ljoin
                  JOIN {course} c ON c.id = ce.courseid
             LEFT JOIN {course_completions} cc ON cc.userid = ce.userid AND cc.course = ce.courseid
             LEFT JOIN {user_lastaccess} la ON la.userid = ce.userid AND la.courseid = ce.courseid
             LEFT JOIN (
                    SELECT ue1.userid, e1.courseid, MIN(ue1.timecreated) AS firstenrolled, COUNT(1) AS anycount,
                           SUM(CASE WHEN ue1.status = 0 AND e1.status = 0 AND ue1.timestart <= :now1
                                     AND (ue1.timeend = 0 OR ue1.timeend > :now2) THEN 1 ELSE 0 END) AS activecount
                      FROM {user_enrolments} ue1
                      JOIN {enrol} e1 ON e1.id = ue1.enrolid
                  GROUP BY ue1.userid, e1.courseid
                  ) ue ON ue.userid = ce.userid AND ue.courseid = ce.courseid
             LEFT JOIN {grade_items} gi ON gi.courseid = ce.courseid AND gi.itemtype = 'course'
             LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = ce.userid
                 WHERE ce.clientid = :clientid AND $lwhere $extra
              ORDER BY c.fullname";
        $rows = $DB->get_records_sql($sql, $params);
        if (!$rows) {
            return [];
        }

        // Tracked activities per course, and completed ones per learner and course.
        $courseids = array_unique(array_map(fn($r) => (int)$r->courseid, $rows));
        [$cin, $cparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cid');
        $totals = $DB->get_records_sql_menu(
            "SELECT cm.course, COUNT(1)
               FROM {course_modules} cm
              WHERE cm.course $cin AND cm.completion > 0 AND cm.deletioninprogress = 0 AND cm.visible = 1
           GROUP BY cm.course",
            $cparams
        );
        $doneparams = $cparams + ['clientid2' => $this->scope->id(), 's1' => COMPLETION_COMPLETE,
            's2' => COMPLETION_COMPLETE_PASS];
        $done = [];
        $donerows = $DB->get_recordset_sql(
            "SELECT cmc.userid, cm.course, COUNT(1) AS done
               FROM {course_modules_completion} cmc
               JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
               JOIN {local_clientportal_enrol} ce2 ON ce2.userid = cmc.userid AND ce2.courseid = cm.course
                    AND ce2.clientid = :clientid2
              WHERE cm.course $cin AND cm.completion > 0 AND cm.deletioninprogress = 0 AND cm.visible = 1
                AND cmc.completionstate IN (:s1, :s2)
           GROUP BY cmc.userid, cm.course",
            $doneparams
        );
        foreach ($donerows as $d) {
            $done[$d->userid . '-' . $d->course] = (int)$d->done;
        }
        $donerows->close();

        foreach ($rows as $row) {
            $row->coursename = format_string($row->coursename, true, ['context' => \context_course::instance($row->courseid)]);
            $row->enrolled = (int)($row->firstenrolled ?: $row->claimed);
            if (!empty($row->activecount)) {
                $row->enrolstatus = self::ENROL_ACTIVE;
            } else if (!empty($row->anycount)) {
                $row->enrolstatus = self::ENROL_SUSPENDED;
            } else {
                $row->enrolstatus = self::ENROL_REMOVED;
            }
            $row->total = (int)($totals[$row->courseid] ?? 0);
            $row->done = min($row->total, $done[$row->userid . '-' . $row->courseid] ?? 0);
            $row->completed = $row->completed ? (int)$row->completed : null;
            $row->lastaccess = $row->lastaccess ? (int)$row->lastaccess : null;
            if ($row->completed) {
                $row->percent = 100;
                $row->status = self::COMPLETED;
            } else {
                $row->percent = $row->total ? (int)floor($row->done * 100 / $row->total) : null;
                $row->status = ($row->done > 0 || $row->lastaccess) ? self::INPROGRESS : self::NOTSTARTED;
            }
            $row->grade = $this->format_course_grade((int)$row->courseid, $row->finalgrade, !empty($row->gradehidden));
        }
        return $rows;
    }

    /**
     * Format a course total grade, or '' when there is none or it is hidden from the learner.
     *
     * @param int $courseid
     * @param mixed $finalgrade
     * @param bool $hidden
     * @return string
     */
    private function format_course_grade(int $courseid, $finalgrade, bool $hidden): string {
        if ($finalgrade === null || $hidden) {
            return '';
        }
        if (!array_key_exists($courseid, $this->courseitems)) {
            $this->courseitems[$courseid] = \grade_item::fetch_course_item($courseid) ?: null;
        }
        $item = $this->courseitems[$courseid];
        if (!$item || $item->hidden) {
            return '';
        }
        // Course totals read best as a percentage for clients.
        return grade_format_gradevalue((float)$finalgrade, $item, true, GRADE_DISPLAY_TYPE_PERCENTAGE, 0);
    }

    /**
     * Headline numbers for the dashboard.
     *
     * @param int|null $groupid
     * @return stdClass
     */
    public function summary(?int $groupid = null): stdClass {
        $s = (object)['learners' => $this->count_learners($groupid), 'enrolments' => 0, 'completed' => 0,
            'inprogress' => 0, 'notstarted' => 0, 'recent' => 0, 'rate' => 0];
        $since = time() - 30 * DAYSECS;
        foreach ($this->enrolments(null, $groupid) as $row) {
            $s->enrolments++;
            $s->{$row->status}++;
            if ($row->completed && $row->completed >= $since) {
                $s->recent++;
            }
        }
        $s->rate = $s->enrolments ? (int)round($s->completed * 100 / $s->enrolments) : 0;
        return $s;
    }

    /**
     * Number of learners.
     *
     * @param int|null $groupid
     * @param string $search
     * @return int
     */
    public function count_learners(?int $groupid = null, string $search = ''): int {
        global $DB;
        [$sql, $params] = $this->learner_query('COUNT(1)', $groupid, $search);
        return (int)$DB->count_records_sql($sql, $params);
    }

    /**
     * One page of learners with their course counts.
     *
     * @param int|null $groupid
     * @param string $search
     * @param string $sort name|lastaccess|email
     * @param int $page
     * @param int $perpage
     * @return stdClass[]
     */
    public function learners(?int $groupid, string $search, string $sort, int $page, int $perpage): array {
        global $DB;
        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        [$sql, $params] = $this->learner_query("u.id, u.email, u.lastaccess, u.suspended, $namefields", $groupid, $search);
        $order = match ($sort) {
            'lastaccess' => 'u.lastaccess DESC, u.lastname, u.firstname',
            'email' => 'u.email',
            default => 'u.lastname, u.firstname, u.id',
        };
        $users = $DB->get_records_sql("$sql ORDER BY $order", $params, $page * $perpage, $perpage);
        if (!$users) {
            return [];
        }
        $groups = $this->user_groups(array_keys($users));
        foreach ($users as $u) {
            $u->courses = $u->completed = $u->inprogress = $u->notstarted = 0;
            $u->groups = $groups[$u->id] ?? [];
        }
        foreach ($this->enrolments(array_keys($users)) as $row) {
            $u = $users[$row->userid];
            $u->courses++;
            $u->{$row->status}++;
        }
        return $users;
    }

    /**
     * Base learner query.
     *
     * @param string $select
     * @param int|null $groupid
     * @param string $search
     * @return array [sql, params]
     */
    private function learner_query(string $select, ?int $groupid, string $search): array {
        global $DB;
        [$join, $where, $params] = $this->scope->learner_sql('u', 'lq');
        $sql = "SELECT $select FROM {user} u $join WHERE $where";
        if ($groupid) {
            $sql .= " AND EXISTS (SELECT 1 FROM {local_clientportal_groupmem} gm WHERE gm.groupid = :lqgroup AND gm.userid = u.id)";
            $params['lqgroup'] = $groupid;
        }
        $search = trim($search);
        if ($search !== '') {
            $full = $DB->sql_concat('u.firstname', "' '", 'u.lastname');
            $sql .= ' AND (' . $DB->sql_like($full, ':s1', false) . ' OR ' . $DB->sql_like('u.email', ':s2', false) . ')';
            $params['s1'] = $params['s2'] = '%' . $DB->sql_like_escape($search) . '%';
        }
        return [$sql, $params];
    }

    /**
     * Client groups each user is in.
     *
     * @param int[] $userids
     * @return array userid => [groupid => name]
     */
    public function user_groups(array $userids): array {
        global $DB;
        if (!$userids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['clientid'] = $this->scope->id();
        $rs = $DB->get_recordset_sql("SELECT gm.id, gm.userid, g.id AS groupid, g.name
                                        FROM {local_clientportal_groupmem} gm
                                        JOIN {local_clientportal_group} g ON g.id = gm.groupid
                                       WHERE g.clientid = :clientid AND gm.userid $in
                                    ORDER BY g.name", $params);
        $out = [];
        foreach ($rs as $r) {
            $out[$r->userid][$r->groupid] = format_string($r->name);
        }
        $rs->close();
        return $out;
    }

    /**
     * Courses the client owns at least one enrolment in.
     *
     * @return array id => name
     */
    public function courses(): array {
        global $DB;
        $rs = $DB->get_records_sql("SELECT DISTINCT c.id, c.fullname
                                      FROM {local_clientportal_enrol} ce
                                      JOIN {course} c ON c.id = ce.courseid
                                     WHERE ce.clientid = :clientid
                                  ORDER BY c.fullname", ['clientid' => $this->scope->id()]);
        $out = [];
        foreach ($rs as $c) {
            $out[$c->id] = format_string($c->fullname, true, ['context' => \context_course::instance($c->id)]);
        }
        return $out;
    }

    /**
     * Most recent course completions.
     *
     * @param int $limit
     * @return stdClass[] rows from enrolments() with user names added
     */
    public function recent_completions(int $limit = 8): array {
        $rows = array_filter($this->enrolments(), fn($r) => !empty($r->completed));
        usort($rows, fn($a, $b) => $b->completed <=> $a->completed);
        $rows = array_slice($rows, 0, $limit);
        $this->add_names($rows);
        return $rows;
    }

    /**
     * Totals per client group (plus learners in no group).
     *
     * @return stdClass[]
     */
    public function group_summary(): array {
        $groups = manager::get_groups($this->scope->id());
        $out = [];
        foreach ($groups as $g) {
            $sum = $this->summary((int)$g->id);
            $sum->name = format_string($g->name);
            $sum->id = (int)$g->id;
            $sum->costcentre = $g->costcentre;
            $out[] = $sum;
        }
        return $out;
    }

    /**
     * Add learner names to rows that have ->userid.
     *
     * @param stdClass[] $rows
     */
    public function add_names(array $rows): void {
        global $DB;
        $ids = array_unique(array_map(fn($r) => (int)$r->userid, $rows));
        if (!$ids) {
            return;
        }
        $namefields = \core_user\fields::for_name()->get_sql('', false, '', '', false)->selects;
        [$in, $params] = $DB->get_in_or_equal($ids);
        $users = $DB->get_records_select('user', "id $in", $params, '', "id, email, $namefields");
        foreach ($rows as $r) {
            $r->fullname = isset($users[$r->userid]) ? fullname($users[$r->userid]) : '';
            $r->email = $users[$r->userid]->email ?? '';
        }
    }

    /**
     * Activities in a course for one learner: completion state and time, grade and availability.
     *
     * @param int $userid
     * @param int $courseid
     * @return array [sections => [name, activities => [...]]]
     */
    public function activities(int $userid, int $courseid): array {
        global $DB;
        $this->scope->require_owned($userid, $courseid);
        $modinfo = get_fast_modinfo($courseid, $userid);
        $completion = $DB->get_records_sql(
            "SELECT cmc.coursemoduleid, cmc.completionstate, cmc.timemodified
               FROM {course_modules_completion} cmc
               JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
              WHERE cm.course = :courseid AND cmc.userid = :userid",
            ['courseid' => $courseid, 'userid' => $userid]
        );

        // Activity grades, keyed by module name and instance (first grade item of each activity).
        $grades = [];
        foreach (\grade_item::fetch_all(['courseid' => $courseid, 'itemtype' => 'mod']) ?: [] as $item) {
            if ((int)$item->itemnumber !== 0) {
                continue;
            }
            $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $userid]);
            $text = '';
            if ($grade && $grade->finalgrade !== null) {
                $text = ($item->is_hidden() || $grade->is_hidden())
                    ? get_string('gradehidden', 'local_clientportal')
                    : grade_format_gradevalue((float)$grade->finalgrade, $item, true, GRADE_DISPLAY_TYPE_REAL, 0) . ' / ' .
                        format_float((float)$item->grademax, 0);
            }
            $grades[$item->itemmodule . '-' . $item->iteminstance] = $text;
        }

        $sections = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            $acts = [];
            foreach ($modinfo->sections[$section->section] ?? [] as $cmid) {
                $cm = $modinfo->get_cm($cmid);
                if (!$cm->visible || $cm->deletioninprogress || !$cm->has_view() && !$cm->completion) {
                    continue;
                }
                $gradekey = $cm->modname . '-' . $cm->instance;
                if (!$cm->completion && !array_key_exists($gradekey, $grades)) {
                    continue;
                }
                $state = $completion[$cm->id]->completionstate ?? COMPLETION_INCOMPLETE;
                $acts[] = (object)[
                    'name' => $cm->get_formatted_name(),
                    'modname' => get_string('modulename', $cm->modname),
                    'icon' => $cm->get_icon_url()->out(false),
                    'tracked' => (bool)$cm->completion,
                    'state' => (int)$state,
                    'time' => ($state > COMPLETION_INCOMPLETE && isset($completion[$cm->id]))
                        ? (int)$completion[$cm->id]->timemodified : null,
                    'grade' => $grades[$gradekey] ?? '',
                    'available' => $cm->uservisible,
                ];
            }
            if ($acts) {
                $sections[] = (object)['name' => get_section_name($courseid, $section), 'activities' => $acts];
            }
        }
        return $sections;
    }

    /**
     * Flat rows for export: one per learner and owned course.
     *
     * @param int|null $groupid
     * @param int|null $courseid
     * @return array [columns, rows]
     */
    public function export(?int $groupid = null, ?int $courseid = null): array {
        $rows = $this->enrolments(null, $groupid, $courseid);
        $this->add_names($rows);
        $groups = $this->user_groups(array_unique(array_map(fn($r) => (int)$r->userid, $rows)));
        $showemail = (bool)get_config('local_clientportal', 'showemail');
        $columns = ['learner' => get_string('learner', 'local_clientportal')];
        if ($showemail) {
            $columns['email'] = get_string('email');
        }
        $columns += [
            'groups' => get_string('groups', 'local_clientportal'),
            'course' => get_string('course'),
            'status' => get_string('status', 'local_clientportal'),
            'progress' => get_string('progress', 'local_clientportal'),
            'activities' => get_string('activitiesdone', 'local_clientportal'),
            'enrolled' => get_string('enrolled', 'local_clientportal'),
            'completed' => get_string('completeddate', 'local_clientportal'),
            'grade' => get_string('grade', 'local_clientportal'),
            'lastaccess' => get_string('lastaccess', 'local_clientportal'),
            'enrolment' => get_string('enrolment', 'local_clientportal'),
        ];
        usort($rows, fn($a, $b) => [$a->fullname, $a->coursename] <=> [$b->fullname, $b->coursename]);
        $fmt = get_string('strftimedatetimeshort', 'langconfig');
        $out = [];
        foreach ($rows as $r) {
            $line = ['learner' => $r->fullname];
            if ($showemail) {
                $line['email'] = $r->email;
            }
            $line += [
                'groups' => implode(', ', $groups[$r->userid] ?? []),
                'course' => $r->coursename,
                'status' => get_string('status_' . $r->status, 'local_clientportal'),
                'progress' => $r->percent === null ? '' : $r->percent . '%',
                'activities' => $r->total ? $r->done . ' / ' . $r->total : '',
                'enrolled' => $r->enrolled ? userdate($r->enrolled, $fmt) : '',
                'completed' => $r->completed ? userdate($r->completed, $fmt) : '',
                'grade' => $r->grade,
                'lastaccess' => $r->lastaccess ? userdate($r->lastaccess, $fmt) : '',
                'enrolment' => get_string('enrol_' . $r->enrolstatus, 'local_clientportal'),
            ];
            $out[] = $line;
        }
        return [$columns, $out];
    }
}
