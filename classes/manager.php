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

use context_system;
use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/cohort/lib.php');

/**
 * Data changes for clients, staff, groups, enrolment ownership and the audit log.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {
    /** Client manager: everything in the portal, including the team list and audit log. */
    public const ROLE_MANAGER = 'manager';
    /** Client representative: learners, groups, reports and certificates. */
    public const ROLE_REP = 'rep';
    /** Shortname of the Moodle role given to every client staff member. */
    public const ROLE_SHORTNAME = 'clientportaluser';

    /**
     * Make sure the "Client portal user" role exists, can only be given at system level,
     * and is barred from the few things that would let staff reach learner data or other people.
     *
     * @return int role id
     */
    public static function ensure_role(): int {
        global $DB;
        $role = $DB->get_record('role', ['shortname' => self::ROLE_SHORTNAME]);
        if ($role) {
            $roleid = (int)$role->id;
        } else {
            $roleid = create_role(
                get_string('rolename', 'local_clientportal'),
                self::ROLE_SHORTNAME,
                get_string('roledescription', 'local_clientportal'),
                ''
            );
        }
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        $context = context_system::instance();
        $prohibit = [
            'moodle/webservice:createtoken',
            'moodle/webservice:createmobiletoken',
            'moodle/site:sendmessage',
            'moodle/course:request',
            'moodle/blog:create',
            'moodle/user:manageownfiles',
            'moodle/portfolio:export',
            'moodle/search:query',
        ];
        foreach ($prohibit as $cap) {
            if (get_capability_info($cap)) {
                assign_capability($cap, CAP_PROHIBIT, $roleid, $context->id, true);
            }
        }
        return $roleid;
    }

    /**
     * Role id of the portal role (created if missing).
     *
     * @return int
     */
    public static function role_id(): int {
        global $DB;
        $id = $DB->get_field('role', 'id', ['shortname' => self::ROLE_SHORTNAME]);
        return $id ? (int)$id : self::ensure_role();
    }

    // Clients.

    /**
     * Get a client.
     *
     * @param int $id
     * @param int $strictness
     * @return stdClass|false
     */
    public static function get_client(int $id, int $strictness = IGNORE_MISSING) {
        global $DB;
        return $DB->get_record('local_clientportal_client', ['id' => $id], '*', $strictness);
    }

    /**
     * All clients with their cohort name and counts.
     *
     * @return stdClass[]
     */
    public static function get_clients(): array {
        global $DB;
        $sql = "SELECT c.*, h.name AS cohortname,
                       (SELECT COUNT(1) FROM {local_clientportal_member} m WHERE m.clientid = c.id) AS staffcount,
                       (SELECT COUNT(1) FROM {cohort_members} cm WHERE cm.cohortid = c.cohortid) AS cohortcount,
                       (SELECT COUNT(1) FROM {local_clientportal_enrol} e WHERE e.clientid = c.id) AS enrolcount
                  FROM {local_clientportal_client} c
                  JOIN {cohort} h ON h.id = c.cohortid
              ORDER BY c.name";
        return $DB->get_records_sql($sql);
    }

    /**
     * Create or update a client. A new cohort is created when cohortid is 0.
     *
     * @param stdClass $data
     * @return int client id
     */
    public static function save_client(stdClass $data): int {
        global $DB;
        $now = time();
        $record = (object)[
            'name' => trim($data->name),
            'abn' => trim($data->abn ?? ''),
            'contactname' => trim($data->contactname ?? ''),
            'contactemail' => trim($data->contactemail ?? ''),
            'phone' => trim($data->phone ?? ''),
            'notes' => $data->notes ?? '',
            'active' => empty($data->active) ? 0 : 1,
            'timemodified' => $now,
        ];
        if (!empty($data->id)) {
            $record->id = (int)$data->id;
            $DB->update_record('local_clientportal_client', $record);
            return $record->id;
        }
        $cohortid = (int)($data->cohortid ?? 0);
        if (!$cohortid) {
            $cohortid = cohort_add_cohort((object)[
                'contextid' => context_system::instance()->id,
                'name' => $record->name,
                'idnumber' => '',
                'description' => '',
                'descriptionformat' => FORMAT_HTML,
                'component' => '',
            ]);
        }
        if ($DB->record_exists('local_clientportal_client', ['cohortid' => $cohortid])) {
            throw new moodle_exception('error_cohorttaken', 'local_clientportal');
        }
        $record->cohortid = $cohortid;
        $record->timecreated = $now;
        return (int)$DB->insert_record('local_clientportal_client', $record);
    }

    /**
     * Delete a client and everything the portal stored for it. The cohort and its members are kept.
     *
     * @param int $clientid
     */
    public static function delete_client(int $clientid): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        foreach ($DB->get_records('local_clientportal_member', ['clientid' => $clientid]) as $member) {
            self::remove_member($clientid, (int)$member->userid);
        }
        $groupids = $DB->get_fieldset_select('local_clientportal_group', 'id', 'clientid = ?', [$clientid]);
        if ($groupids) {
            [$in, $params] = $DB->get_in_or_equal($groupids);
            $DB->delete_records_select('local_clientportal_groupmem', "groupid $in", $params);
        }
        $DB->delete_records('local_clientportal_group', ['clientid' => $clientid]);
        $DB->delete_records('local_clientportal_enrol', ['clientid' => $clientid]);
        $DB->delete_records('local_clientportal_log', ['clientid' => $clientid]);
        $DB->delete_records('local_clientportal_client', ['id' => $clientid]);
        $transaction->allow_commit();
        guard::reset_cache();
    }

    /**
     * Cohorts that can become a client (system cohorts not already used).
     *
     * @return array id => name
     */
    public static function available_cohorts(): array {
        global $DB;
        $sql = "SELECT h.id, h.name
                  FROM {cohort} h
             LEFT JOIN {local_clientportal_client} c ON c.cohortid = h.id
                 WHERE c.id IS NULL
              ORDER BY h.name";
        return $DB->get_records_sql_menu($sql);
    }

    // Staff (managers and representatives).

    /**
     * Staff of a client.
     *
     * @param int $clientid
     * @return stdClass[] member records with user name fields
     */
    public static function get_members(int $clientid): array {
        global $DB;
        $fields = \core_user\fields::for_name()->with_identity(context_system::instance())->get_sql('u', false, '', '', false);
        $sql = "SELECT m.id AS memberid, m.role, m.timecreated AS membersince, u.id, u.email, u.lastaccess,
                       u.suspended, {$fields->selects}
                  FROM {local_clientportal_member} m
                  JOIN {user} u ON u.id = m.userid AND u.deleted = 0
                 WHERE m.clientid = :clientid
              ORDER BY m.role, u.lastname, u.firstname";
        return $DB->get_records_sql($sql, ['clientid' => $clientid] + $fields->params);
    }

    /**
     * Add (or change the role of) a client staff member. The user joins the client cohort (so cohort
     * branding applies) and gets the portal role. Users with course enrolments are refused: client staff
     * use a separate account from any learner account.
     *
     * @param int $clientid
     * @param int $userid
     * @param string $role manager|rep
     */
    public static function add_member(int $clientid, int $userid, string $role): void {
        global $DB, $USER;
        if (!in_array($role, [self::ROLE_MANAGER, self::ROLE_REP], true)) {
            throw new moodle_exception('error_badrole', 'local_clientportal');
        }
        $client = self::get_client($clientid, MUST_EXIST);
        $user = \core_user::get_user($userid, '*', MUST_EXIST);
        if (is_siteadmin($user)) {
            throw new moodle_exception('error_staffadmin', 'local_clientportal');
        }
        if ($existing = $DB->get_record('local_clientportal_member', ['clientid' => $clientid, 'userid' => $userid])) {
            if ($existing->role !== $role) {
                $DB->set_field('local_clientportal_member', 'role', $role, ['id' => $existing->id]);
                self::log($clientid, 'staff_role_changed', $userid, $role);
            }
            return;
        }
        if (enrol_get_all_users_courses($userid, false)) {
            throw new moodle_exception('error_staffenrolled', 'local_clientportal', '', fullname($user));
        }
        $transaction = $DB->start_delegated_transaction();
        $DB->insert_record('local_clientportal_member', (object)[
            'clientid' => $clientid,
            'userid' => $userid,
            'role' => $role,
            'createdby' => (int)$USER->id,
            'timecreated' => time(),
        ]);
        // Staff are not learners: take them out of any client groups.
        self::remove_user_from_client_groups($clientid, $userid);
        cohort_add_member($client->cohortid, $userid);
        role_assign(self::role_id(), $userid, context_system::instance()->id, 'local_clientportal');
        self::log($clientid, 'staff_added', $userid, $role);
        $transaction->allow_commit();
        guard::reset_cache($userid);
    }

    /**
     * Remove a staff member from a client. They leave the cohort, and lose the portal role when they
     * are no longer staff of any client.
     *
     * @param int $clientid
     * @param int $userid
     */
    public static function remove_member(int $clientid, int $userid): void {
        global $DB;
        $client = self::get_client($clientid, MUST_EXIST);
        if (!$DB->record_exists('local_clientportal_member', ['clientid' => $clientid, 'userid' => $userid])) {
            return;
        }
        $DB->delete_records('local_clientportal_member', ['clientid' => $clientid, 'userid' => $userid]);
        cohort_remove_member($client->cohortid, $userid);
        if (!$DB->record_exists('local_clientportal_member', ['userid' => $userid])) {
            role_unassign(self::role_id(), $userid, context_system::instance()->id, 'local_clientportal');
        }
        self::log($clientid, 'staff_removed', $userid);
        guard::reset_cache($userid);
    }

    // Groups.

    /**
     * Groups of a client with learner counts.
     *
     * @param int $clientid
     * @return stdClass[]
     */
    public static function get_groups(int $clientid): array {
        global $DB;
        $sql = "SELECT g.*, (SELECT COUNT(1) FROM {local_clientportal_groupmem} gm WHERE gm.groupid = g.id) AS members
                  FROM {local_clientportal_group} g
                 WHERE g.clientid = :clientid
              ORDER BY g.name";
        return $DB->get_records_sql($sql, ['clientid' => $clientid]);
    }

    /**
     * Get a group, but only if it belongs to the client.
     *
     * @param int $clientid
     * @param int $groupid
     * @return stdClass
     */
    public static function get_group(int $clientid, int $groupid): stdClass {
        global $DB;
        return $DB->get_record('local_clientportal_group', ['id' => $groupid, 'clientid' => $clientid], '*', MUST_EXIST);
    }

    /**
     * Create or update a group.
     *
     * @param int $clientid
     * @param stdClass $data name, description, costcentre, optional id
     * @return int
     */
    public static function save_group(int $clientid, stdClass $data): int {
        global $DB;
        $record = (object)[
            'clientid' => $clientid,
            'name' => trim($data->name),
            'description' => trim($data->description ?? ''),
            'costcentre' => trim($data->costcentre ?? ''),
            'timemodified' => time(),
        ];
        if (!empty($data->id)) {
            $record->id = self::get_group($clientid, (int)$data->id)->id;
            $DB->update_record('local_clientportal_group', $record);
            self::log($clientid, 'group_updated', null, $record->name);
            return (int)$record->id;
        }
        $record->timecreated = $record->timemodified;
        $id = (int)$DB->insert_record('local_clientportal_group', $record);
        self::log($clientid, 'group_created', null, $record->name);
        return $id;
    }

    /**
     * Delete a group (learners are not affected).
     *
     * @param int $clientid
     * @param int $groupid
     */
    public static function delete_group(int $clientid, int $groupid): void {
        global $DB;
        $group = self::get_group($clientid, $groupid);
        $DB->delete_records('local_clientportal_groupmem', ['groupid' => $group->id]);
        $DB->delete_records('local_clientportal_group', ['id' => $group->id]);
        self::log($clientid, 'group_deleted', null, $group->name);
    }

    /**
     * Put learners in a group. Anyone who is not a learner of this client is ignored.
     *
     * @param int $clientid
     * @param int $groupid
     * @param int[] $userids
     * @return int number added
     */
    public static function add_to_group(int $clientid, int $groupid, array $userids): int {
        global $DB;
        $group = self::get_group($clientid, $groupid);
        $added = 0;
        foreach (array_unique(array_map('intval', $userids)) as $userid) {
            if (!scope::is_learner($clientid, $userid)) {
                continue;
            }
            if (!$DB->record_exists('local_clientportal_groupmem', ['groupid' => $group->id, 'userid' => $userid])) {
                $DB->insert_record(
                    'local_clientportal_groupmem',
                    (object)['groupid' => $group->id, 'userid' => $userid, 'timecreated' => time()]
                );
                $added++;
            }
        }
        if ($added) {
            self::log($clientid, 'group_members_added', null, $group->name . ' (' . $added . ')');
        }
        return $added;
    }

    /**
     * Take learners out of a group.
     *
     * @param int $clientid
     * @param int $groupid
     * @param int[] $userids
     * @return int number removed
     */
    public static function remove_from_group(int $clientid, int $groupid, array $userids): int {
        global $DB;
        $group = self::get_group($clientid, $groupid);
        $userids = array_unique(array_map('intval', $userids));
        if (!$userids) {
            return 0;
        }
        [$in, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['groupid'] = $group->id;
        $count = $DB->count_records_select('local_clientportal_groupmem', "groupid = :groupid AND userid $in", $params);
        $DB->delete_records_select('local_clientportal_groupmem', "groupid = :groupid AND userid $in", $params);
        if ($count) {
            self::log($clientid, 'group_members_removed', null, $group->name . ' (' . $count . ')');
        }
        return $count;
    }

    /**
     * Remove a user from every group of a client.
     *
     * @param int $clientid
     * @param int $userid
     */
    public static function remove_user_from_client_groups(int $clientid, int $userid): void {
        global $DB;
        $DB->delete_records_select(
            'local_clientportal_groupmem',
            'userid = :userid AND groupid IN (SELECT id FROM {local_clientportal_group} WHERE clientid = :clientid)',
            ['userid' => $userid, 'clientid' => $clientid]
        );
    }

    // Enrolment ownership.

    /**
     * Record that a client owns (paid for) a learner's course enrolment. An enrolment has one owner;
     * an enrolment already owned by another client is left alone.
     *
     * @param int $clientid
     * @param int $userid
     * @param int $courseid
     * @param string $source auto|backfill|admin|portal
     * @return bool true when a claim was recorded
     */
    public static function claim(int $clientid, int $userid, int $courseid, string $source): bool {
        global $DB, $USER;
        if ($DB->record_exists('local_clientportal_enrol', ['userid' => $userid, 'courseid' => $courseid])) {
            return false;
        }
        try {
            $DB->insert_record('local_clientportal_enrol', (object)[
                'clientid' => $clientid,
                'userid' => $userid,
                'courseid' => $courseid,
                'source' => $source,
                'createdby' => isloggedin() ? (int)$USER->id : 0,
                'timecreated' => time(),
            ]);
        } catch (\dml_write_exception $e) {
            // Claimed at the same moment by another request.
            return false;
        }
        return true;
    }

    /**
     * Claim every existing, unowned enrolment of the client's learners.
     *
     * @param int $clientid
     * @return int number claimed
     */
    public static function claim_existing(int $clientid): int {
        global $DB;
        $client = self::get_client($clientid, MUST_EXIST);
        $sql = "SELECT DISTINCT ue.userid, e.courseid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {cohort_members} cm ON cm.userid = ue.userid AND cm.cohortid = :cohortid
                  JOIN {user} u ON u.id = ue.userid AND u.deleted = 0
             LEFT JOIN {local_clientportal_enrol} ce ON ce.userid = ue.userid AND ce.courseid = e.courseid
             LEFT JOIN {local_clientportal_member} m ON m.userid = ue.userid AND m.clientid = :clientid
                 WHERE ce.id IS NULL AND m.id IS NULL AND e.courseid <> :siteid";
        $rows = $DB->get_recordset_sql($sql, ['cohortid' => $client->cohortid, 'clientid' => $clientid, 'siteid' => SITEID]);
        $count = 0;
        foreach ($rows as $row) {
            if (self::claim($clientid, (int)$row->userid, (int)$row->courseid, 'backfill')) {
                $count++;
            }
        }
        $rows->close();
        self::log($clientid, 'enrolments_claimed', null, (string)$count);
        return $count;
    }

    /**
     * Release an owned enrolment (the client stops seeing it).
     *
     * @param int $claimid
     * @return stdClass|null the deleted claim
     */
    public static function release_claim(int $claimid): ?stdClass {
        global $DB;
        $claim = $DB->get_record('local_clientportal_enrol', ['id' => $claimid]);
        if (!$claim) {
            return null;
        }
        $DB->delete_records('local_clientportal_enrol', ['id' => $claimid]);
        self::log((int)$claim->clientid, 'enrolment_released', (int)$claim->userid, (string)$claim->courseid);
        return $claim;
    }

    // Audit log.

    /**
     * Write to the portal audit log.
     *
     * @param int $clientid
     * @param string $action
     * @param int|null $relateduserid
     * @param string|null $details
     */
    public static function log(int $clientid, string $action, ?int $relateduserid = null, ?string $details = null): void {
        global $DB, $USER;
        $DB->insert_record('local_clientportal_log', (object)[
            'clientid' => $clientid,
            'userid' => isloggedin() ? (int)$USER->id : 0,
            'action' => $action,
            'relateduserid' => $relateduserid,
            'details' => $details,
            'timecreated' => time(),
        ]);
    }
}
