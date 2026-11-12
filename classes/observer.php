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

/**
 * Event observers.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * A learner was enrolled outside the portal. When the setting is on and the learner belongs to
     * exactly one client, that client owns the enrolment. (Learners in two or more clients are left
     * for an administrator, so one client never sees another's courses.)
     *
     * @param \core\event\user_enrolment_created $event
     */
    public static function user_enrolment_created(\core\event\user_enrolment_created $event): void {
        global $DB;
        if (!get_config('local_clientportal', 'autoclaim')) {
            return;
        }
        $userid = (int)$event->relateduserid;
        $courseid = (int)$event->courseid;
        if (!$userid || !$courseid || $courseid == SITEID) {
            return;
        }
        $sql = "SELECT c.id
                  FROM {local_clientportal_client} c
                  JOIN {cohort_members} cm ON cm.cohortid = c.cohortid AND cm.userid = :userid
                 WHERE NOT EXISTS (SELECT 1 FROM {local_clientportal_member} m WHERE m.clientid = c.id AND m.userid = :userid2)";
        $clients = $DB->get_fieldset_sql($sql, ['userid' => $userid, 'userid2' => $userid]);
        if (count($clients) === 1) {
            manager::claim((int)reset($clients), $userid, $courseid, 'auto');
        }
    }

    /**
     * Someone left a client cohort: they leave its groups; staff stop being staff.
     *
     * @param \core\event\cohort_member_removed $event
     */
    public static function cohort_member_removed(\core\event\cohort_member_removed $event): void {
        global $DB;
        $client = $DB->get_record('local_clientportal_client', ['cohortid' => $event->objectid]);
        if (!$client) {
            return;
        }
        $userid = (int)$event->relateduserid;
        manager::remove_user_from_client_groups((int)$client->id, $userid);
        if ($DB->record_exists('local_clientportal_member', ['clientid' => $client->id, 'userid' => $userid])) {
            manager::remove_member((int)$client->id, $userid);
        }
    }

    /**
     * A client's cohort was deleted: delete the client's portal data.
     *
     * @param \core\event\cohort_deleted $event
     */
    public static function cohort_deleted(\core\event\cohort_deleted $event): void {
        global $DB;
        if ($client = $DB->get_record('local_clientportal_client', ['cohortid' => $event->objectid])) {
            manager::delete_client((int)$client->id);
        }
    }

    /**
     * A user was deleted.
     *
     * @param \core\event\user_deleted $event
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        global $DB;
        $userid = (int)$event->objectid;
        $DB->delete_records('local_clientportal_member', ['userid' => $userid]);
        $DB->delete_records('local_clientportal_groupmem', ['userid' => $userid]);
        $DB->delete_records('local_clientportal_enrol', ['userid' => $userid]);
        guard::reset_cache($userid);
    }

    /**
     * A course was deleted.
     *
     * @param \core\event\course_deleted $event
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        global $DB;
        $DB->delete_records('local_clientportal_enrol', ['courseid' => $event->objectid]);
    }
}
