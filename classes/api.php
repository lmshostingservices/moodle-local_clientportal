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
 * Public API for other plugins.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api {
    /**
     * Record billable usage, for example AI credits a plugin spent for a user. The portal works out the client from
     * the context (a client's own course) or the user. Safe to call when the user belongs to no client: nothing is
     * recorded.
     *
     * Example: \local_clientportal\api::record_usage($USER->id, $context->id, 'mod_contentcreator', 5, 'Voiceover');
     *
     * @param int $userid
     * @param int $contextid
     * @param string $component
     * @param float $credits
     * @param string $description
     * @return int usage id, or 0
     */
    public static function record_usage(
        int $userid,
        int $contextid,
        string $component,
        float $credits,
        string $description = ''
    ): int {
        return usage::record($userid, $contextid, $component, $credits, $description);
    }

    /**
     * Clients a user is a learner of (cohort members who are not staff).
     *
     * @param int $userid
     * @return int[]
     */
    public static function learner_clients(int $userid): array {
        global $DB;
        $sql = "SELECT c.id
                  FROM {local_clientportal_client} c
                  JOIN {cohort_members} cm ON cm.cohortid = c.cohortid AND cm.userid = :u
                 WHERE NOT EXISTS (SELECT 1 FROM {local_clientportal_member} m WHERE m.clientid = c.id AND m.userid = :u2)";
        return array_map('intval', $DB->get_fieldset_sql($sql, ['u' => $userid, 'u2' => $userid]));
    }

    /**
     * Is the user portal staff of the client?
     *
     * @param int $clientid
     * @param int $userid
     * @return bool
     */
    public static function is_staff(int $clientid, int $userid): bool {
        global $DB;
        return $DB->record_exists('local_clientportal_member', ['clientid' => $clientid, 'userid' => $userid]);
    }

    /**
     * The client that owns a course through its category, or 0.
     *
     * @param int $courseid
     * @return int
     */
    public static function course_client(int $courseid): int {
        return courses::owner($courseid);
    }
}
