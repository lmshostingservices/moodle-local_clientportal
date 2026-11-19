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

use local_clientportal\manager;
use local_clientportal\scope;
use stdClass;

/**
 * Runs compliance rules: assigns and enrols learners, records completions, opens the next cycle (clearing the learner's
 * progress), and sends reminders.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine {
    /** @var int current time, fixed for one run (tests set it) */
    protected int $now;

    /** @var array counts for the run */
    public array $stats = ['assigned' => 0, 'completed' => 0, 'reopened' => 0, 'cancelled' => 0, 'reminded' => 0,
        'managers' => 0, 'failed' => 0];

    /**
     * Constructor.
     *
     * @param int|null $now
     */
    public function __construct(?int $now = null) {
        $this->now = $now ?? time();
    }

    /**
     * Run every switched-on rule of every active client, or of one client.
     *
     * @param int $clientid 0 = all clients
     * @param int $ruleid 0 = all rules
     * @return array stats
     */
    public function run(int $clientid = 0, int $ruleid = 0): array {
        global $DB;
        $params = ['enabled' => 1];
        $where = 'r.enabled = :enabled AND c.active = 1';
        if ($clientid) {
            $where .= ' AND r.clientid = :clientid';
            $params['clientid'] = $clientid;
        }
        if ($ruleid) {
            $where .= ' AND r.id = :ruleid';
            $params['ruleid'] = $ruleid;
        }
        $rules = $DB->get_records_sql(
            "SELECT r.* FROM {local_clientportal_crule} r
               JOIN {local_clientportal_client} c ON c.id = r.clientid
               JOIN {course} co ON co.id = r.courseid
              WHERE $where
           ORDER BY r.clientid, r.id",
            $params
        );
        $scopes = [];
        foreach ($rules as $rule) {
            if (!isset($scopes[$rule->clientid])) {
                $scopes[$rule->clientid] = new scope(
                    manager::get_client((int)$rule->clientid, MUST_EXIST),
                    manager::ROLE_MANAGER,
                    true
                );
            }
            try {
                $this->run_rule($scopes[$rule->clientid], $rule);
            } catch (\Throwable $e) {
                $this->stats['failed']++;
                debugging('Compliance rule ' . $rule->id . ' failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        return $this->stats;
    }

    /**
     * Run one rule.
     *
     * @param scope $scope
     * @param stdClass $rule
     */
    public function run_rule(scope $scope, stdClass $rule): void {
        global $DB;
        $members = array_flip(rules::members($scope, $rule));
        // Latest cycle per learner.
        $latest = [];
        $rows = $DB->get_records('local_clientportal_cdue', ['ruleid' => $rule->id], 'userid, cycle');
        foreach ($rows as $row) {
            $latest[(int)$row->userid] = $row;
        }

        foreach (array_keys($members) as $userid) {
            $row = $latest[$userid] ?? null;
            if (!$row) {
                $latest[$userid] = $this->assign($scope, $rule, $userid, 1, null);
                continue;
            }
            if ($row->status === 'cancelled') {
                $latest[$userid] = $this->assign($scope, $rule, $userid, (int)$row->cycle + 1, null);
                continue;
            }
            if ($row->status === 'open') {
                $this->check_completion($rule, $row);
            }
            if (
                $row->status === 'completed' && $row->nextdue
                    && $this->now >= $row->nextdue - (int)$rule->leaddays * DAYSECS
            ) {
                $latest[$userid] = $this->assign($scope, $rule, $userid, (int)$row->cycle + 1, $row);
            }
        }

        // People no longer covered by the rule: their open cycle ends. Their course is not touched.
        foreach ($latest as $userid => $row) {
            if (!isset($members[$userid]) && $row->status === 'open') {
                $DB->update_record('local_clientportal_cdue', (object)['id' => $row->id, 'status' => 'cancelled',
                    'timemodified' => $this->now]);
                $row->status = 'cancelled';
                $this->stats['cancelled']++;
            }
        }

        $this->remind($scope, $rule, array_filter($latest, fn($r) => $r->status === 'open'
            && isset($members[(int)$r->userid])));
    }

    /**
     * Start a cycle: enrol the learner if needed, clear last cycle's progress, set the due date.
     *
     * @param scope $scope
     * @param stdClass $rule
     * @param int $userid
     * @param int $cycle
     * @param stdClass|null $previous the completed cycle being renewed
     * @return stdClass the new cycle
     */
    protected function assign(scope $scope, stdClass $rule, int $userid, int $cycle, ?stdClass $previous): stdClass {
        global $DB;
        $courseid = (int)$rule->courseid;
        if ($previous) {
            $summary = resetter::reset($userid, $courseid);
            $DB->update_record('local_clientportal_cdue', (object)['id' => $previous->id,
                'history' => json_encode($summary), 'timemodified' => $this->now]);
            $due = max((int)$previous->nextdue, rules::end_of_day($this->now));
            $this->stats['reopened']++;
        } else {
            $due = rules::first_due($rule, $this->now);
            $this->stats['assigned']++;
        }
        self::enrol($scope, $courseid, $userid);
        $row = (object)[
            'ruleid' => $rule->id,
            'clientid' => $scope->id(),
            'userid' => $userid,
            'courseid' => $courseid,
            'cycle' => $cycle,
            'status' => 'open',
            'timeassigned' => $this->now,
            'timedue' => $due,
            'timecompleted' => 0,
            'nextdue' => 0,
            'sent' => '',
            'lastoverdue' => 0,
            'timemodified' => $this->now,
        ];
        $row->id = $DB->insert_record('local_clientportal_cdue', $row);
        // A first cycle may already be done (completed before the rule existed).
        if (!$previous && $this->check_completion($rule, $row)) {
            return $row;
        }
        // Reminder points already passed are not sent on top of this first message.
        $passed = array_filter(rules::reminder_days($rule), fn($d) => $this->now >= $due - $d * DAYSECS - DAYSECS + 1);
        if (notifier::learner($scope, $rule, $row, $previous ? 'renew' : 'assigned', $this->now)) {
            $this->mark($row, 'a', ...array_map(fn($d) => 'b' . $d, $passed));
        }
        return $row;
    }

    /**
     * Record a completion. A renewed cycle only counts a completion made after it opened.
     *
     * @param stdClass $rule
     * @param stdClass $row updated in place
     * @return bool completed
     */
    public function check_completion(stdClass $rule, stdClass $row): bool {
        global $DB;
        $done = (int)$DB->get_field('course_completions', 'timecompleted', ['course' => $row->courseid,
            'userid' => $row->userid]);
        if (!$done || ((int)$row->cycle > 1 && $done < (int)$row->timeassigned - DAYSECS)) {
            return false;
        }
        $row->status = 'completed';
        $row->timecompleted = $done;
        $row->nextdue = rules::next_due($rule, $done);
        $row->grade = resetter::course_grade((int)$row->userid, (int)$row->courseid);
        $row->timemodified = $this->now;
        $DB->update_record('local_clientportal_cdue', $row);
        $this->stats['completed']++;
        return true;
    }

    /**
     * Reminders before the due date, while overdue, and the manager's summary.
     *
     * @param scope $scope
     * @param stdClass $rule
     * @param stdClass[] $open open cycles
     */
    protected function remind(scope $scope, stdClass $rule, array $open): void {
        $days = rules::reminder_days($rule);
        $late = [];
        foreach ($open as $row) {
            $due = (int)$row->timedue;
            $sent = self::sent($row);
            if ($this->now <= $due) {
                // The most recent reminder point passed and not yet sent; earlier ones are skipped.
                $point = null;
                foreach ($days as $d) {
                    if ($this->now >= $due - $d * DAYSECS - DAYSECS + 1 && !in_array('b' . $d, $sent, true)) {
                        $point = $d;
                    }
                }
                if ($point !== null) {
                    $kind = $point === 0 ? 'today' : 'reminder';
                    $tags = array_map(fn($d) => 'b' . $d, array_filter($days, fn($d) => $d >= $point));
                    if (notifier::learner($scope, $rule, $row, $kind, $this->now)) {
                        $this->mark($row, ...$tags);
                        $this->stats['reminded']++;
                    }
                }
                continue;
            }
            // Overdue.
            if (
                (int)$rule->overdueevery > 0 && (!$row->lastoverdue
                    || $this->now - (int)$row->lastoverdue >= (int)$rule->overdueevery * DAYSECS - HOURSECS)
            ) {
                if (notifier::learner($scope, $rule, $row, 'overdue', $this->now)) {
                    $this->update($row, ['lastoverdue' => $this->now]);
                    $this->stats['reminded']++;
                }
            }
            if (
                (int)$rule->managerafter >= 0 && $this->now >= $due + (int)$rule->managerafter * DAYSECS
                    && !in_array('m', $sent, true)
            ) {
                $late[] = $row;
            }
        }
        if ($late) {
            $told = notifier::managers($scope, $rule, $late, $this->now);
            if ($told) {
                foreach ($late as $row) {
                    $this->mark($row, 'm');
                }
                $this->stats['managers'] += $told;
            }
        }
    }

    /**
     * Send one reminder now, whatever the schedule (the "Remind now" button).
     *
     * @param scope $scope
     * @param int $dueid
     * @return bool
     */
    public static function remind_now(scope $scope, int $dueid): bool {
        global $DB;
        $row = $DB->get_record('local_clientportal_cdue', ['id' => $dueid, 'clientid' => $scope->id(),
            'status' => 'open'], '*', MUST_EXIST);
        $rule = rules::get($scope, (int)$row->ruleid);
        $kind = time() > (int)$row->timedue ? 'overdue' : 'reminder';
        $ok = notifier::learner($scope, $rule, $row, $kind, time());
        if ($ok) {
            manager::log($scope->id(), 'rule_reminded', (int)$row->userid, rules::course_name((int)$row->courseid));
        }
        return $ok;
    }

    /**
     * Enrol the learner as a student (manual enrolment) and record that this client owns the enrolment.
     * A suspended enrolment is left suspended.
     *
     * @param scope $scope
     * @param int $courseid
     * @param int $userid
     */
    public static function enrol(scope $scope, int $courseid, int $userid): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/enrollib.php');
        $context = \context_course::instance($courseid);
        if (!is_enrolled($context, $userid, '', false)) {
            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
            $plugin = enrol_get_plugin('manual');
            $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual'], '*', IGNORE_MULTIPLE);
            if (!$instance) {
                $instance = $DB->get_record('enrol', ['id' => $plugin->add_instance($course)], '*', MUST_EXIST);
            } else if ((int)$instance->status !== ENROL_INSTANCE_ENABLED) {
                $plugin->update_status($instance, ENROL_INSTANCE_ENABLED);
            }
            $roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
            $plugin->enrol_user($instance, $userid, $roleid ?: null, time());
        }
        manager::claim($scope->id(), $userid, $courseid, 'compliance');
    }

    /**
     * Reminder tags sent this cycle.
     *
     * @param stdClass $row
     * @return string[]
     */
    protected static function sent(stdClass $row): array {
        return $row->sent === '' ? [] : explode(',', $row->sent);
    }

    /**
     * Add reminder tags to a cycle.
     *
     * @param stdClass $row
     * @param string ...$tags
     */
    protected function mark(stdClass $row, string ...$tags): void {
        $this->update($row, ['sent' => implode(',', array_unique(array_merge(self::sent($row), $tags)))]);
    }

    /**
     * Update a cycle in the database and in memory.
     *
     * @param stdClass $row
     * @param array $fields
     */
    protected function update(stdClass $row, array $fields): void {
        global $DB;
        foreach ($fields as $k => $v) {
            $row->$k = $v;
        }
        $DB->update_record('local_clientportal_cdue', (object)(['id' => $row->id, 'timemodified' => $this->now] + $fields));
    }
}
