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

use moodle_exception;
use stdClass;

/**
 * One account across several clients: each person has a main client, which decides their branding and
 * who is billed when it is not otherwise clear.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class people {
    /**
     * May one account be a learner in one client and staff in another?
     *
     * @return bool
     */
    public static function one_account(): bool {
        $value = get_config('local_clientportal', 'oneaccount');
        return $value === false || (bool)$value;
    }

    /**
     * Clients a person belongs to (as a learner or staff), in the order they joined.
     *
     * @param int $userid
     * @return int[] client ids
     */
    public static function client_ids(int $userid): array {
        global $DB;
        $sql = "SELECT c.id, cm.timeadded
                  FROM {local_clientportal_client} c
                  JOIN {cohort_members} cm ON cm.cohortid = c.cohortid
                 WHERE cm.userid = :userid
              ORDER BY cm.timeadded, c.id";
        return array_map('intval', array_keys($DB->get_records_sql_menu($sql, ['userid' => $userid])));
    }

    /**
     * The person's main client, or 0 when they belong to none.
     *
     * @param int $userid
     * @return int
     */
    public static function main_client(int $userid): int {
        global $DB;
        return (int)$DB->get_field('local_clientportal_main', 'clientid', ['userid' => $userid]);
    }

    /**
     * Make a client the person's main client. They must belong to it.
     *
     * @param int $userid
     * @param int $clientid
     */
    public static function set_main_client(int $userid, int $clientid): void {
        global $DB;
        if (!in_array($clientid, self::client_ids($userid), true)) {
            throw new moodle_exception('error_notinclient', 'local_clientportal');
        }
        $record = (object)['userid' => $userid, 'clientid' => $clientid, 'timemodified' => time()];
        if ($id = $DB->get_field('local_clientportal_main', 'id', ['userid' => $userid])) {
            $record->id = $id;
            $DB->update_record('local_clientportal_main', $record);
        } else {
            $DB->insert_record('local_clientportal_main', $record);
        }
    }

    /**
     * Keep the main client valid: set it when missing, move it when the person left that client, and remove
     * it when they belong to no client. A client where the person is staff is preferred.
     *
     * @param int $userid
     * @return int the main client after the check (0 for none)
     */
    public static function ensure_main(int $userid): int {
        global $DB;
        $clients = self::client_ids($userid);
        $main = self::main_client($userid);
        if (!$clients) {
            $DB->delete_records('local_clientportal_main', ['userid' => $userid]);
            return 0;
        }
        if ($main && in_array($main, $clients, true)) {
            return $main;
        }
        $staff = array_map('intval', $DB->get_fieldset_select('local_clientportal_member', 'clientid', 'userid = ?', [$userid]));
        $choice = array_values(array_intersect($clients, $staff))[0] ?? $clients[0];
        self::set_main_client($userid, $choice);
        return $choice;
    }

    /**
     * How many other clients each person also belongs to. Names are never given out.
     *
     * @param int[] $userids
     * @param int $clientid the client asking
     * @return int[] userid => count of other clients (people with none are left out)
     */
    public static function other_client_counts(array $userids, int $clientid): array {
        global $DB;
        if (!$userids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal(array_map('intval', $userids), SQL_PARAMS_NAMED, 'ou');
        $sql = "SELECT cm.userid, COUNT(DISTINCT c.id) AS others
                  FROM {cohort_members} cm
                  JOIN {local_clientportal_client} c ON c.cohortid = cm.cohortid
                 WHERE cm.userid $in AND c.id <> :clientid
              GROUP BY cm.userid";
        return array_map('intval', $DB->get_records_sql_menu($sql, $params + ['clientid' => $clientid]));
    }

    /**
     * Find an existing account by email, so one person keeps one account.
     *
     * @param string $email
     * @return stdClass|null the user, or null when there is none
     * @throws moodle_exception when several accounts share the email
     */
    public static function find_by_email(string $email): ?stdClass {
        global $CFG, $DB;
        $select = 'LOWER(email) = LOWER(:email) AND deleted = 0 AND mnethostid = :host';
        $users = $DB->get_records_select('user', $select, ['email' => trim($email), 'host' => $CFG->mnet_localhost_id], 'id');
        if (count($users) > 1) {
            throw new moodle_exception('error_emailshared', 'local_clientportal', '', s(trim($email)));
        }
        return $users ? reset($users) : null;
    }
}
