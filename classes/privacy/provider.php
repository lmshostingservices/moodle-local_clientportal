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

namespace local_clientportal\privacy;

use context;
use context_system;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider. All portal data is stored at system context.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe stored data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_clientportal_member', [
            'clientid' => 'privacy:metadata:member:clientid',
            'userid' => 'privacy:metadata:member:userid',
            'role' => 'privacy:metadata:member:role',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:member');
        $collection->add_database_table('local_clientportal_enrol', [
            'clientid' => 'privacy:metadata:enrol:clientid',
            'userid' => 'privacy:metadata:enrol:userid',
            'courseid' => 'privacy:metadata:enrol:courseid',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:enrol');
        $collection->add_database_table('local_clientportal_groupmem', [
            'groupid' => 'privacy:metadata:groupmem:groupid',
            'userid' => 'privacy:metadata:groupmem:userid',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:groupmem');
        $collection->add_database_table('local_clientportal_log', [
            'userid' => 'privacy:metadata:log:userid',
            'action' => 'privacy:metadata:log:action',
            'relateduserid' => 'privacy:metadata:log:relateduserid',
            'details' => 'privacy:metadata:log:details',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:log');
        return $collection;
    }

    /**
     * Does the user have any portal data?
     *
     * @param int $userid
     * @return bool
     */
    private static function has_data(int $userid): bool {
        global $DB;
        return $DB->record_exists('local_clientportal_member', ['userid' => $userid]) ||
            $DB->record_exists('local_clientportal_enrol', ['userid' => $userid]) ||
            $DB->record_exists('local_clientportal_groupmem', ['userid' => $userid]) ||
            $DB->record_exists_select('local_clientportal_log', 'userid = ? OR relateduserid = ?', [$userid, $userid]);
    }

    /**
     * Contexts with data for a user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $list = new contextlist();
        if (self::has_data($userid)) {
            $list->add_system_context();
        }
        return $list;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        if (!$userlist->get_context() instanceof context_system) {
            return;
        }
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_clientportal_member}', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_clientportal_enrol}', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_clientportal_groupmem}', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_clientportal_log}', []);
        $userlist->add_from_sql('relateduserid', 'SELECT relateduserid FROM {local_clientportal_log}
                                                  WHERE relateduserid IS NOT NULL', []);
    }

    /**
     * Export a user's data.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_system) {
                continue;
            }
            $sub = [get_string('pluginname', 'local_clientportal')];
            $clients = $DB->get_records_menu('local_clientportal_client', null, '', 'id, name');
            $staff = array_map(fn($m) => [
                'client' => $clients[$m->clientid] ?? '',
                'role' => get_string('role_' . $m->role, 'local_clientportal'),
                'since' => transform::datetime($m->timecreated),
            ], array_values($DB->get_records('local_clientportal_member', ['userid' => $userid])));
            $owned = array_map(fn($e) => [
                'client' => $clients[$e->clientid] ?? '',
                'courseid' => $e->courseid,
                'since' => transform::datetime($e->timecreated),
            ], array_values($DB->get_records('local_clientportal_enrol', ['userid' => $userid])));
            $groups = array_values($DB->get_fieldset_sql('SELECT g.name FROM {local_clientportal_groupmem} gm
                                                            JOIN {local_clientportal_group} g ON g.id = gm.groupid
                                                           WHERE gm.userid = ?', [$userid]));
            $log = array_map(fn($l) => [
                'action' => $l->action,
                'details' => $l->details,
                'byyou' => transform::yesno((int)$l->userid === $userid),
                'time' => transform::datetime($l->timecreated),
            ], array_values($DB->get_records_select(
                'local_clientportal_log',
                'userid = ? OR relateduserid = ?',
                [$userid, $userid],
                'timecreated'
            )));
            writer::with_context($context)->export_data($sub, (object)[
                'staff' => $staff,
                'ownedenrolments' => $owned,
                'groups' => $groups,
                'log' => $log,
            ]);
        }
    }

    /**
     * Delete everyone's data in a context.
     *
     * @param context $context
     */
    public static function delete_data_for_all_users_in_context(context $context) {
        global $DB;
        if (!$context instanceof context_system) {
            return;
        }
        $DB->delete_records('local_clientportal_member');
        $DB->delete_records('local_clientportal_enrol');
        $DB->delete_records('local_clientportal_groupmem');
        $DB->delete_records('local_clientportal_log');
    }

    /**
     * Delete one user's data.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof context_system) {
                self::delete_users([(int)$contextlist->get_user()->id]);
            }
        }
    }

    /**
     * Delete several users' data.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        if ($userlist->get_context() instanceof context_system) {
            self::delete_users($userlist->get_userids());
        }
    }

    /**
     * Delete rows for users. Log rows written by others about the user keep the action but lose the link and details.
     *
     * @param int[] $userids
     */
    private static function delete_users(array $userids): void {
        global $DB;
        if (!$userids) {
            return;
        }
        [$in, $params] = $DB->get_in_or_equal($userids);
        $DB->delete_records_select('local_clientportal_member', "userid $in", $params);
        $DB->delete_records_select('local_clientportal_enrol', "userid $in", $params);
        $DB->delete_records_select('local_clientportal_groupmem', "userid $in", $params);
        $DB->delete_records_select('local_clientportal_log', "userid $in", $params);
        // Details can name the learner (for example a certificate file name), so they go too.
        $DB->set_field_select('local_clientportal_log', 'details', null, "relateduserid $in", $params);
        $DB->set_field_select('local_clientportal_log', 'relateduserid', null, "relateduserid $in", $params);
    }
}
