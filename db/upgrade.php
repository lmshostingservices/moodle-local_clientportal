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
            new xmldb_field('features', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '', 'active'),
            new xmldb_field('categoryid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'features'),
            new xmldb_field('billing', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'categoryid'),
            new xmldb_field('ratecredit', XMLDB_TYPE_NUMBER, '10, 4', null, XMLDB_NOTNULL, null, '0', 'billing'),
            new xmldb_field('ratelearner', XMLDB_TYPE_NUMBER, '10, 4', null, XMLDB_NOTNULL, null, '0', 'ratecredit'),
            new xmldb_field('rateenrol', XMLDB_TYPE_NUMBER, '10, 4', null, XMLDB_NOTNULL, null, '0', 'ratelearner'),
            new xmldb_field('xerocontact', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '', 'rateenrol'),
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
            $table->add_field('component', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
            $table->add_field('kind', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'aicredits');
            $table->add_field('quantity', XMLDB_TYPE_NUMBER, '12, 2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('description', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
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
            $table->add_field('lines', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('total', XMLDB_TYPE_NUMBER, '12, 2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('xeroinvoiceid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
            $table->add_field('xeronumber', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
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
    return true;
}
