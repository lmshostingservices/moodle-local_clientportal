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

    $ADMIN->add('local_clientportal_cat', new admin_externalpage(
        'local_clientportal_usage',
        get_string('usagereport', 'local_clientportal'),
        new moodle_url('/local/clientportal/admin/usage.php'),
        'local/clientportal:manageclients'
    ));
    $ADMIN->add('local_clientportal_cat', new admin_externalpage(
        'local_clientportal_separation',
        get_string('separation', 'local_clientportal'),
        new moodle_url('/local/clientportal/admin/separation.php'),
        'moodle/site:config'
    ));
    $ADMIN->add('local_clientportal_cat', new admin_externalpage(
        'local_clientportal_xero',
        get_string('xero', 'local_clientportal'),
        new moodle_url('/local/clientportal/admin/xero.php'),
        'moodle/site:config'
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
        $settings->add(new admin_setting_heading(
            'local_clientportal/onboardinghdr',
            get_string('onboardingsettings', 'local_clientportal'),
            get_string('onboardingsettings_desc', 'local_clientportal')
        ));
        $settings->add(new admin_setting_configcheckbox(
            'local_clientportal/onboardenrol',
            get_string('onboardenrol', 'local_clientportal'),
            get_string('onboardenrol_desc', 'local_clientportal'),
            1
        ));
        $settings->add(new admin_setting_configcheckbox(
            'local_clientportal/managersignoff',
            get_string('managersignoff', 'local_clientportal'),
            get_string('managersignoff_desc', 'local_clientportal'),
            1
        ));
        $settings->add(new admin_setting_heading(
            'local_clientportal/courseshdr',
            get_string('clientcoursessettings', 'local_clientportal'),
            get_string('clientcoursessettings_desc', 'local_clientportal')
        ));
        $categories = [0 => get_string('top')];
        if (class_exists('core_course_category')) {
            $categories += core_course_category::make_categories_list();
        }
        $settings->add(new admin_setting_configselect(
            'local_clientportal/categoryparent',
            get_string('categoryparent', 'local_clientportal'),
            get_string('categoryparent_desc', 'local_clientportal'),
            0,
            $categories
        ));

        $settings->add(new admin_setting_heading(
            'local_clientportal/billinghdr',
            get_string('billingsettings', 'local_clientportal'),
            get_string('billingsettings_desc', 'local_clientportal')
        ));
        foreach (\local_clientportal\usage::AI_DEFAULTS as $action => $credits) {
            $settings->add(new admin_setting_configtext(
                'local_clientportal/aicredits_' . $action,
                get_string('aicredits', 'local_clientportal', get_string('ai_' . $action, 'local_clientportal')),
                '',
                $credits,
                PARAM_FLOAT
            ));
        }
        $settings->add(new admin_setting_configtext(
            'local_clientportal/xeroclientid',
            get_string('xeroclientid', 'local_clientportal'),
            get_string(
                'xeroclientid_desc',
                'local_clientportal',
                (new moodle_url('/local/clientportal/admin/xero.php'))->out(false)
            ),
            '',
            PARAM_ALPHANUMEXT
        ));
        $settings->add(new admin_setting_configpasswordunmask(
            'local_clientportal/xeroclientsecret',
            get_string('xeroclientsecret', 'local_clientportal'),
            '',
            ''
        ));
        $settings->add(new admin_setting_configselect(
            'local_clientportal/xeroscopes',
            get_string('xeroscopes', 'local_clientportal'),
            get_string('xeroscopes_desc', 'local_clientportal'),
            'granular',
            ['granular' => get_string('xeroscopes_granular', 'local_clientportal'),
                'broad' => get_string('xeroscopes_broad', 'local_clientportal')]
        ));
        $settings->add(new admin_setting_configtext(
            'local_clientportal/xeroaccount',
            get_string('xeroaccount', 'local_clientportal'),
            get_string('xeroaccount_desc', 'local_clientportal'),
            '200',
            PARAM_ALPHANUMEXT
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
