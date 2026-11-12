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
 * Client Portal - admin settings and pages.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig || has_capability('local/clientportal:manageclients', context_system::instance())) {
    $ADMIN->add('localplugins', new admin_category('local_clientportal_cat', get_string('pluginname', 'local_clientportal')));
    $ADMIN->add('local_clientportal_cat', new admin_externalpage(
        'local_clientportal_clients',
        get_string('clients', 'local_clientportal'),
        new moodle_url('/local/clientportal/admin/clients.php'),
        'local/clientportal:manageclients'
    ));

    $settings = new admin_settingpage('local_clientportal', get_string('settings', 'local_clientportal'));
    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_configcheckbox(
            'local_clientportal/autoclaim',
            get_string('autoclaim', 'local_clientportal'),
            get_string('autoclaim_desc', 'local_clientportal'),
            1
        ));
        $settings->add(new admin_setting_configcheckbox(
            'local_clientportal/showemail',
            get_string('showemail', 'local_clientportal'),
            get_string('showemail_desc', 'local_clientportal'),
            1
        ));
        $settings->add(new admin_setting_configtextarea(
            'local_clientportal/extraallowed',
            get_string('extraallowed', 'local_clientportal'),
            get_string('extraallowed_desc', 'local_clientportal'),
            '',
            PARAM_TEXT
        ));
    }
    $ADMIN->add('local_clientportal_cat', $settings);
}
