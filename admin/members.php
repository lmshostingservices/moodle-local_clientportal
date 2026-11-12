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
 * Client Portal - client staff (site administration).
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_clientportal\form\member_form;
use local_clientportal\form\member_new_form;
use local_clientportal\manager;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/user/lib.php');

$clientid = required_param('client', PARAM_INT);
$pageurl = new moodle_url('/local/clientportal/admin/members.php', ['client' => $clientid]);
admin_externalpage_setup('local_clientportal_clients', '', null, $pageurl);
require_capability('local/clientportal:manageclients', context_system::instance());
$client = manager::get_client($clientid, MUST_EXIST);

// Remove or change role.
if (($remove = optional_param('remove', 0, PARAM_INT)) && confirm_sesskey()) {
    manager::remove_member($clientid, $remove);
    redirect($pageurl, get_string('staffremoved', 'local_clientportal'), null, \core\output\notification::NOTIFY_SUCCESS);
}
if (($change = optional_param('change', 0, PARAM_INT)) && confirm_sesskey()) {
    manager::add_member($clientid, $change, required_param('role', PARAM_ALPHA));
    redirect($pageurl, get_string('staffupdated', 'local_clientportal'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$existing = new member_form($pageurl, ['new' => false], 'post', '', ['id' => 'cport-existing']);
$new = new member_new_form($pageurl, [], 'post', '', ['id' => 'cport-new']);
$mode = optional_param('mode', '', PARAM_ALPHA);
$error = null;
if ($mode === 'existing' && ($data = $existing->get_data())) {
    try {
        manager::add_member($clientid, (int)$data->userid, $data->role);
        redirect($pageurl, get_string('staffadded', 'local_clientportal'), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
} else if ($mode === 'new' && ($data = $new->get_data())) {
    try {
        $user = manager::create_staff($clientid, $data->firstname, $data->lastname, $data->email, $data->role);
        redirect(
            $pageurl,
            get_string('staffcreated', 'local_clientportal', s($user->email)),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
}
$existing->set_data(['client' => $clientid]);
$new->set_data(['client' => $clientid]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('stafffor', 'local_clientportal', format_string($client->name)));
echo html_writer::tag('p', get_string('staffintro', 'local_clientportal'));
if ($error) {
    echo $OUTPUT->notification($error, 'error');
}

$members = manager::get_members($clientid);
if ($members) {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable';
    $table->head = [get_string('name'), get_string('email'), get_string('role', 'local_clientportal'), ''];
    foreach ($members as $m) {
        $links = [];
        foreach ([manager::ROLE_MANAGER, manager::ROLE_REP, manager::ROLE_TRAINER] as $other) {
            if ($other !== $m->role) {
                $links[] = html_writer::link(
                    new moodle_url($pageurl, ['change' => $m->id, 'role' => $other, 'sesskey' => sesskey()]),
                    get_string('makerole', 'local_clientportal', get_string('role_' . $other, 'local_clientportal'))
                );
            }
        }
        $switch = implode(' · ', $links);
        $remove = html_writer::link(
            new moodle_url($pageurl, ['remove' => $m->id, 'sesskey' => sesskey()]),
            get_string('remove'),
            ['class' => 'text-danger']
        );
        $table->data[] = [fullname($m), s($m->email), get_string('role_' . $m->role, 'local_clientportal'),
            $switch . ' · ' . $remove];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('nostaff', 'local_clientportal'), 'info');
}

echo $OUTPUT->heading(get_string('addexistingstaff', 'local_clientportal'), 3);
$existing->display();
echo $OUTPUT->heading(get_string('createnewstaff', 'local_clientportal'), 3);
echo html_writer::tag('p', get_string('createnewstaff_desc', 'local_clientportal'), ['class' => 'text-muted']);
$new->display();
echo html_writer::link(new moodle_url('/local/clientportal/admin/clients.php'), get_string('backtoclients', 'local_clientportal'));
echo $OUTPUT->footer();
