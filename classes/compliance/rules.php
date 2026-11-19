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

namespace local_clientportal\compliance;

use local_clientportal\courses;
use local_clientportal\integration\matrix;
use local_clientportal\manager;
use local_clientportal\scope;
use moodle_exception;
use stdClass;

/**
 * Compliance rules: which learners must complete which course, by when, how often, and the reminders they get.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rules {
    /** @var string[] who a rule applies to */
    const AUDIENCES = ['all', 'group', 'department', 'position'];

    /** @var string[] how a rule repeats */
    const RECUR = ['none', 'days', 'months', 'yearly'];

    /**
     * Every rule of a client.
     *
     * @param scope $scope
     * @return stdClass[]
     */
    public static function all(scope $scope): array {
        global $DB;
        return $DB->get_records_sql(
            "SELECT r.*, c.fullname AS coursename
               FROM {local_clientportal_crule} r
               JOIN {course} c ON c.id = r.courseid
              WHERE r.clientid = :clientid
           ORDER BY c.fullname, r.id",
            ['clientid' => $scope->id()]
        );
    }

    /**
     * One rule, only when it belongs to this client.
     *
     * @param scope $scope
     * @param int $ruleid
     * @return stdClass
     */
    public static function get(scope $scope, int $ruleid): stdClass {
        global $DB;
        $rule = $DB->get_record('local_clientportal_crule', ['id' => $ruleid, 'clientid' => $scope->id()]);
        if (!$rule) {
            throw new moodle_exception('error_norule', 'local_clientportal');
        }
        return $rule;
    }

    /**
     * Courses a rule can use: the client's own courses and courses its learners are already enrolled in through it.
     * Site staff can choose any course.
     *
     * @param scope $scope
     * @return string[] course id => name
     */
    public static function course_options(scope $scope): array {
        global $DB;
        $params = ['clientid' => $scope->id(), 'siteid' => SITEID];
        $where = "c.id IN (SELECT ce.courseid FROM {local_clientportal_enrol} ce WHERE ce.clientid = :clientid)";
        $catids = courses::category_ids($scope->client);
        if ($catids) {
            [$in, $inparams] = $DB->get_in_or_equal($catids, SQL_PARAMS_NAMED, 'rc');
            $where .= " OR c.category $in";
            $params += $inparams;
        }
        if ($scope->support) {
            $where = '1 = 1';
        }
        $rows = $DB->get_records_sql_menu(
            "SELECT c.id, c.fullname FROM {course} c WHERE c.id <> :siteid AND ($where) ORDER BY c.fullname",
            $params
        );
        return array_map(fn($n) => format_string($n, true, ['context' => \context_system::instance()]), $rows);
    }

    /**
     * Choices for each kind of audience.
     *
     * @param scope $scope
     * @return array kind => [id => name]
     */
    public static function audience_options(scope $scope): array {
        $out = ['group' => [], 'department' => [], 'position' => []];
        foreach (manager::get_groups($scope->id()) as $g) {
            $out['group'][$g->id] = format_string($g->name);
        }
        if (matrix::available()) {
            $out['department'] = matrix::departments($scope);
            foreach (matrix::positions($scope) as $p) {
                $out['position'][$p->id] = format_string($p->name);
            }
        }
        return $out;
    }

    /**
     * Save a rule.
     *
     * @param scope $scope
     * @param stdClass $data form data
     * @return int rule id
     */
    public static function save(scope $scope, stdClass $data): int {
        global $DB, $USER;
        if (!isset(self::course_options($scope)[(int)$data->courseid])) {
            throw new moodle_exception('error_notyourcourse', 'local_clientportal');
        }
        $audience = in_array($data->audience ?? 'all', self::AUDIENCES, true) ? $data->audience : 'all';
        $audienceid = $audience === 'all' ? 0 : (int)($data->{'audience_' . $audience} ?? $data->audienceid ?? 0);
        if ($audience !== 'all' && !isset(self::audience_options($scope)[$audience][$audienceid])) {
            throw new moodle_exception('error_noaudience', 'local_clientportal');
        }
        $recur = in_array($data->recurunit ?? 'none', self::RECUR, true) ? $data->recurunit : 'none';
        $now = time();
        $record = (object)[
            'clientid' => $scope->id(),
            'courseid' => (int)$data->courseid,
            'audience' => $audience,
            'audienceid' => $audienceid,
            'duetype' => ($data->duetype ?? 'days') === 'date' ? 'date' : 'days',
            'duedays' => max(0, min(3650, (int)($data->duedays ?? 30))),
            'duedate' => (int)($data->duedate ?? 0),
            'recurunit' => $recur,
            'recurvalue' => $recur === 'days' || $recur === 'months' ? max(1, min(3650, (int)($data->recurvalue ?? 12))) : 0,
            'leaddays' => max(0, min(365, (int)($data->leaddays ?? 30))),
            'reminders' => self::clean_reminders((string)($data->reminders ?? '')),
            'overdueevery' => max(0, min(365, (int)($data->overdueevery ?? 7))),
            'managerafter' => max(-1, min(365, (int)($data->managerafter ?? 7))),
            'message' => trim((string)($data->message ?? '')),
            'competencyid' => (int)($data->competencyid ?? 0),
            'enabled' => empty($data->enabled) ? 0 : 1,
            'timemodified' => $now,
        ];
        if ($record->duetype === 'date' && !$record->duedate) {
            throw new moodle_exception('error_noduedate', 'local_clientportal');
        }
        if (!empty($data->id)) {
            $old = self::get($scope, (int)$data->id);
            $record->id = $old->id;
            $DB->update_record('local_clientportal_crule', $record);
            if ((int)$old->courseid !== $record->courseid) {
                // A different course starts again: open cycles for the old course end.
                $DB->set_field_select(
                    'local_clientportal_cdue',
                    'status',
                    'cancelled',
                    "ruleid = ? AND status = 'open'",
                    [$record->id]
                );
            }
            manager::log($scope->id(), 'rule_updated', null, self::course_name($record->courseid));
            return (int)$record->id;
        }
        $record->createdby = (int)$USER->id;
        $record->timecreated = $now;
        $id = (int)$DB->insert_record('local_clientportal_crule', $record);
        manager::log($scope->id(), 'rule_created', null, self::course_name($record->courseid));
        return $id;
    }

    /**
     * Delete a rule. Completion history goes with it; course progress is not touched.
     *
     * @param scope $scope
     * @param int $ruleid
     */
    public static function delete(scope $scope, int $ruleid): void {
        global $DB;
        $rule = self::get($scope, $ruleid);
        $DB->delete_records('local_clientportal_cdue', ['ruleid' => $rule->id]);
        $DB->delete_records('local_clientportal_crule', ['id' => $rule->id]);
        manager::log($scope->id(), 'rule_deleted', null, self::course_name((int)$rule->courseid));
    }

    /**
     * Turn a rule on or off. Off keeps its history and stops reminders and new cycles.
     *
     * @param scope $scope
     * @param int $ruleid
     * @param bool $enabled
     */
    public static function set_enabled(scope $scope, int $ruleid, bool $enabled): void {
        global $DB;
        $rule = self::get($scope, $ruleid);
        $DB->set_field('local_clientportal_crule', 'enabled', $enabled ? 1 : 0, ['id' => $rule->id]);
    }

    /**
     * "14, 3, 0" style list, highest first, no repeats, at most 6.
     *
     * @param string $text
     * @return string
     */
    public static function clean_reminders(string $text): string {
        $days = [];
        foreach (preg_split('/[^0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY) as $d) {
            $d = (int)$d;
            if ($d <= 365) {
                $days[$d] = $d;
            }
        }
        krsort($days);
        return implode(',', array_slice(array_values($days), 0, 6));
    }

    /**
     * Reminder days of a rule, highest first.
     *
     * @param stdClass $rule
     * @return int[]
     */
    public static function reminder_days(stdClass $rule): array {
        $text = self::clean_reminders((string)$rule->reminders);
        return $text === '' ? [] : array_map('intval', explode(',', $text));
    }

    /**
     * The learners a rule applies to.
     *
     * @param scope $scope
     * @param stdClass $rule
     * @return int[]
     */
    public static function members(scope $scope, stdClass $rule): array {
        global $DB;
        [$join, $where, $params] = $scope->learner_sql('u', 'rm');
        $extra = '';
        if ($rule->audience === 'group') {
            $extra = "AND EXISTS (SELECT 1 FROM {local_clientportal_groupmem} gm
                                   JOIN {local_clientportal_group} g ON g.id = gm.groupid
                                  WHERE gm.userid = u.id AND g.id = :rgroup AND g.clientid = :rgclient)";
            $params += ['rgroup' => (int)$rule->audienceid, 'rgclient' => $scope->id()];
        }
        $ids = array_map('intval', $DB->get_fieldset_sql(
            "SELECT u.id FROM {user} u $join WHERE $where AND u.suspended = 0 $extra",
            $params
        ));
        if ($rule->audience === 'department' || $rule->audience === 'position') {
            if (!matrix::available()) {
                return [];
            }
            $field = $rule->audience === 'department' ? 'departmentid' : 'positionid';
            $staff = matrix::staff($scope, $ids);
            $ids = array_values(array_filter($ids, fn($id) => isset($staff[$id])
                && (int)$staff[$id]->{$field} === (int)$rule->audienceid));
        }
        return $ids;
    }

    /**
     * When the first cycle is due.
     *
     * @param stdClass $rule
     * @param int $assigned
     * @return int
     */
    public static function first_due(stdClass $rule, int $assigned): int {
        if ($rule->duetype === 'date' && $rule->duedate) {
            return self::end_of_day(max((int)$rule->duedate, $assigned));
        }
        return self::end_of_day($assigned + (int)$rule->duedays * DAYSECS);
    }

    /**
     * When the next cycle is due after a completion, 0 when the rule does not repeat.
     *
     * @param stdClass $rule
     * @param int $completed
     * @return int
     */
    public static function next_due(stdClass $rule, int $completed): int {
        switch ($rule->recurunit) {
            case 'days':
                return self::end_of_day($completed + (int)$rule->recurvalue * DAYSECS);
            case 'months':
                $d = new \DateTime('@' . $completed);
                $d->setTimezone(\core_date::get_server_timezone_object());
                $d->modify('+' . (int)$rule->recurvalue . ' months');
                return self::end_of_day($d->getTimestamp());
            case 'yearly':
                // The same date every year: the rule's due date, or the completion date.
                $anchor = $rule->duetype === 'date' && $rule->duedate ? (int)$rule->duedate : $completed;
                $tz = \core_date::get_server_timezone_object();
                $a = (new \DateTime('@' . $anchor))->setTimezone($tz);
                $c = (new \DateTime('@' . $completed))->setTimezone($tz);
                $year = (int)$c->format('Y') + 1;
                $next = (new \DateTime('now', $tz))->setDate($year, (int)$a->format('n'), (int)$a->format('j'));
                // Completed well before this year's date: due on this year's date instead.
                $thisyear = (clone $next)->setDate($year - 1, (int)$a->format('n'), (int)$a->format('j'));
                if ($thisyear->getTimestamp() - $completed > 60 * DAYSECS) {
                    $next = $thisyear;
                }
                return self::end_of_day($next->getTimestamp());
        }
        return 0;
    }

    /**
     * 23:59:59 on the day of a time, in the server time zone.
     *
     * @param int $time
     * @return int
     */
    public static function end_of_day(int $time): int {
        return usergetmidnight($time, \core_date::get_server_timezone()) + DAYSECS - 1;
    }

    /**
     * Does any switched-on rule cover this learner's course? Training Matrix uses this to leave reminders to the portal.
     *
     * @param int $userid
     * @param int $courseid
     * @return bool
     */
    public static function covers(int $userid, int $courseid): bool {
        global $DB;
        return $DB->record_exists_sql(
            "SELECT 1
               FROM {local_clientportal_cdue} d
               JOIN {local_clientportal_crule} r ON r.id = d.ruleid AND r.enabled = 1
              WHERE d.userid = :userid AND d.courseid = :courseid AND d.status <> 'cancelled'",
            ['userid' => $userid, 'courseid' => $courseid]
        );
    }

    /**
     * Plain words for who a rule applies to.
     *
     * @param scope $scope
     * @param stdClass $rule
     * @param array|null $options audience_options(), passed in when listing many rules
     * @return string
     */
    public static function describe_audience(scope $scope, stdClass $rule, ?array $options = null): string {
        if ($rule->audience === 'all') {
            return get_string('rule_audience_all', 'local_clientportal');
        }
        $options = $options ?? self::audience_options($scope);
        $name = $options[$rule->audience][$rule->audienceid] ?? get_string('rule_audience_missing', 'local_clientportal');
        return get_string('rule_audience_' . $rule->audience, 'local_clientportal') . ': ' . $name;
    }

    /**
     * Plain words for when a rule is due.
     *
     * @param stdClass $rule
     * @return string
     */
    public static function describe_due(stdClass $rule): string {
        if ($rule->duetype === 'date') {
            return get_string('rule_due_date', 'local_clientportal', userdate(
                (int)$rule->duedate,
                get_string('strftimedate', 'langconfig')
            ));
        }
        return get_string(
            (int)$rule->duedays === 1 ? 'rule_due_day' : 'rule_due_days',
            'local_clientportal',
            (int)$rule->duedays
        );
    }

    /**
     * Plain words for how a rule repeats.
     *
     * @param stdClass $rule
     * @return string
     */
    public static function describe_recur(stdClass $rule): string {
        switch ($rule->recurunit) {
            case 'days':
            case 'months':
                $one = (int)$rule->recurvalue === 1;
                return get_string(
                    'rule_recur_' . $rule->recurunit . ($one ? '1' : ''),
                    'local_clientportal',
                    (int)$rule->recurvalue
                );
            case 'yearly':
                return get_string('rule_recur_yearly', 'local_clientportal');
        }
        return get_string('rule_recur_none', 'local_clientportal');
    }

    /**
     * A course's name.
     *
     * @param int $courseid
     * @return string
     */
    public static function course_name(int $courseid): string {
        global $DB;
        $name = $DB->get_field('course', 'fullname', ['id' => $courseid]);
        return $name === false ? (string)$courseid : format_string($name, true, ['context' => \context_system::instance()]);
    }
}
