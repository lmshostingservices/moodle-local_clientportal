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

/**
 * Client Portal - upgrade steps.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_clientportal_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026111310) {
        // Client courses, teachers, assessors and billing.
        $table = new xmldb_table('local_clientportal_client');
        $fields = [
            new xmldb_field('features', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'active'),
            new xmldb_field('categoryid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'features'),
            new xmldb_field('billing', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'categoryid'),
            new xmldb_field('ratecredit', XMLDB_TYPE_NUMBER, '10, 4', null, XMLDB_NOTNULL, null, '0', 'billing'),
            new xmldb_field('ratelearner', XMLDB_TYPE_NUMBER, '10, 4', null, XMLDB_NOTNULL, null, '0', 'ratecredit'),
            new xmldb_field('rateenrol', XMLDB_TYPE_NUMBER, '10, 4', null, XMLDB_NOTNULL, null, '0', 'ratelearner'),
            new xmldb_field('xerocontact', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'rateenrol'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $table = new xmldb_table('local_clientportal_member');
        $field = new xmldb_field('assessor', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'role');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('local_clientportal_usage');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('clientid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('contextid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('component', XMLDB_TYPE_CHAR, '100', null, null, null, null);
            $table->add_field('kind', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'aicredits');
            $table->add_field('quantity', XMLDB_TYPE_NUMBER, '12, 2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('description', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('source', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'api');
            $table->add_field('sourceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('clientid', XMLDB_KEY_FOREIGN, ['clientid'], 'local_clientportal_client', ['id']);
            $table->add_index('clienttime', XMLDB_INDEX_NOTUNIQUE, ['clientid', 'timecreated']);
            $table->add_index('sourceid', XMLDB_INDEX_NOTUNIQUE, ['source', 'sourceid']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_clientportal_invoice');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('clientid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('periodstart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('periodend', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('lineitems', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('total', XMLDB_TYPE_NUMBER, '12, 2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('xeroinvoiceid', XMLDB_TYPE_CHAR, '64', null, null, null, null);
            $table->add_field('xeronumber', XMLDB_TYPE_CHAR, '64', null, null, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('error', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('clientid', XMLDB_KEY_FOREIGN, ['clientid'], 'local_clientportal_client', ['id']);
            $dbman->create_table($table);
        }
        \local_clientportal\courses::ensure_roles();
        upgrade_plugin_savepoint(true, 2026111310, 'local', 'clientportal');
    }

    if ($oldversion < 2026111700) {
        $table = new xmldb_table('local_clientportal_main');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('clientid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('clientid', XMLDB_KEY_FOREIGN, ['clientid'], 'local_clientportal_client', ['id']);
            $table->add_index('userid', XMLDB_INDEX_UNIQUE, ['userid']);
            $dbman->create_table($table);
        }
        // Everyone already in a client gets a main client.
        $userids = $DB->get_fieldset_sql('SELECT DISTINCT cm.userid
                                            FROM {cohort_members} cm
                                            JOIN {local_clientportal_client} c ON c.cohortid = cm.cohortid');
        foreach ($userids as $userid) {
            \local_clientportal\people::ensure_main((int)$userid);
        }
        upgrade_plugin_savepoint(true, 2026111700, 'local', 'clientportal');
    }

    if ($oldversion < 2026112000) {
        $table = new xmldb_table('local_clientportal_crule');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('clientid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('audience', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'all');
            $table->add_field('audienceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('duetype', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'days');
            $table->add_field('duedays', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '30');
            $table->add_field('duedate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('recurunit', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'none');
            $table->add_field('recurvalue', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('leaddays', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '30');
            $table->add_field('reminders', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '14,3,0');
            $table->add_field('overdueevery', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '7');
            $table->add_field('managerafter', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '7');
            $table->add_field('message', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('competencyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('clientid', XMLDB_KEY_FOREIGN, ['clientid'], 'local_clientportal_client', ['id']);
            $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_clientportal_cdue');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('ruleid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('clientid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('cycle', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'open');
            $table->add_field('timeassigned', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timedue', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecompleted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('nextdue', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('grade', XMLDB_TYPE_CHAR, '50', null, null, null, null);
            $table->add_field('sent', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('lastoverdue', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('history', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('ruleid', XMLDB_KEY_FOREIGN, ['ruleid'], 'local_clientportal_crule', ['id']);
            $table->add_key('clientid', XMLDB_KEY_FOREIGN, ['clientid'], 'local_clientportal_client', ['id']);
            $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $table->add_index('ruleusercycle', XMLDB_INDEX_UNIQUE, ['ruleid', 'userid', 'cycle']);
            $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026112000, 'local', 'clientportal');
    }

    if ($oldversion < 2026112500) {
        // 2.4.0: shared courses (keep clients apart with Moodle groups).
        $table = new xmldb_table('local_clientportal_shared');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('groupmode', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('locked', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('applyactivities', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('prevgroupmode', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('prevgroupmodeforce', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('courseid', XMLDB_KEY_FOREIGN_UNIQUE, ['courseid'], 'course', ['id']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_clientportal_sharedcm');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('sharedid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('prevgroupmode', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('sharedid', XMLDB_KEY_FOREIGN, ['sharedid'], 'local_clientportal_shared', ['id']);
            $table->add_key('cmid', XMLDB_KEY_FOREIGN, ['cmid'], 'course_modules', ['id']);
            $table->add_index('sharedcm', XMLDB_INDEX_UNIQUE, ['sharedid', 'cmid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026112500, 'local', 'clientportal');
    }

    return true;
}
