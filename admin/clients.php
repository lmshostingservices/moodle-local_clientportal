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
 * Client Portal - manage clients (site administration).
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_clientportal\manager;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_clientportal_clients');
require_capability('local/clientportal:manageclients', context_system::instance());

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$baseurl = new moodle_url('/local/clientportal/admin/clients.php');

if ($action === 'delete' && $id) {
    $client = manager::get_client($id, MUST_EXIST);
    if (optional_param('confirm', 0, PARAM_BOOL) && confirm_sesskey()) {
        manager::delete_client($id);
        redirect(
            $baseurl,
            get_string('clientdeleted', 'local_clientportal', format_string($client->name)),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('deleteclientconfirm', 'local_clientportal', format_string($client->name)),
        new moodle_url($baseurl, ['action' => 'delete', 'id' => $id, 'confirm' => 1, 'sesskey' => sesskey()]),
        $baseurl
    );
    echo $OUTPUT->footer();
    exit;
}

$clients = manager::get_clients();
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('clients', 'local_clientportal'));
echo html_writer::tag('p', get_string('clientsintro', 'local_clientportal'));
echo html_writer::div($OUTPUT->single_button(
    new moodle_url('/local/clientportal/admin/client.php'),
    get_string('addclient', 'local_clientportal'),
    'get',
    ['type' => single_button::BUTTON_PRIMARY]
) . ' ' . html_writer::link(
    new moodle_url('/local/clientportal/admin/report.php'),
    get_string('clientsreport', 'local_clientportal'),
    ['class' => 'btn btn-secondary']
) . ' ' . html_writer::link(
    new moodle_url('/local/clientportal/admin/usage.php'),
    get_string('usagereport', 'local_clientportal'),
    ['class' => 'btn btn-secondary']
) . ' ' . html_writer::link(
    new moodle_url('/local/clientportal/admin/separation.php'),
    get_string('separation', 'local_clientportal'),
    ['class' => 'btn btn-secondary']
), 'mb-3');
$checks = \local_clientportal\separation::checks();
$open = count(array_filter($checks, fn($c) => !$c->ok));
if ($open) {
    echo $OUTPUT->notification(get_string('separation_open', 'local_clientportal', $open) . ' ' .
        html_writer::link(new moodle_url('/local/clientportal/admin/separation.php'), get_string(
            'separation_review',
            'local_clientportal'
        )), 'warning');
}

if (!$clients) {
    echo $OUTPUT->notification(get_string('noclients', 'local_clientportal'), 'info');
    echo $OUTPUT->footer();
    exit;
}
$table = new html_table();
$table->attributes['class'] = 'generaltable';
$table->head = [get_string('clientname', 'local_clientportal'), get_string('cohort', 'local_clientportal'),
    get_string('staff', 'local_clientportal'), get_string('cohortmembers', 'local_clientportal'),
    get_string('ownedenrolments', 'local_clientportal'), get_string('clientfeatures', 'local_clientportal'),
    get_string('active', 'local_clientportal'), ''];
$branding = class_exists('\\local_cohortbranding\\manager');
foreach ($clients as $c) {
    $links = [
        html_writer::link(
            new moodle_url('/local/clientportal/index.php', ['client' => $c->id]),
            get_string('openportal', 'local_clientportal')
        ),
        html_writer::link(new moodle_url('/local/clientportal/admin/client.php', ['id' => $c->id]), get_string('edit')),
        html_writer::link(
            new moodle_url('/local/clientportal/admin/members.php', ['client' => $c->id]),
            get_string('staff', 'local_clientportal')
        ),
        html_writer::link(
            new moodle_url('/local/clientportal/admin/claims.php', ['client' => $c->id]),
            get_string('ownedenrolments', 'local_clientportal')
        ),
        html_writer::link(
            new moodle_url('/cohort/assign.php', ['id' => $c->cohortid]),
            get_string('cohortmembers', 'local_clientportal')
        ),
    ];
    if ($branding) {
        $links[] = html_writer::link(
            new moodle_url('/local/cohortbranding/index.php'),
            get_string('branding', 'local_clientportal')
        );
    }
    $links[] = html_writer::link(
        new moodle_url($baseurl, ['action' => 'delete', 'id' => $c->id]),
        get_string('delete'),
        ['class' => 'text-danger']
    );
    $table->data[] = [
        html_writer::tag('strong', format_string($c->name)),
        format_string($c->cohortname),
        $c->staffcount,
        max(0, $c->cohortcount - $c->staffcount),
        $c->enrolcount,
        implode(', ', array_map(
            fn($f) => get_string('feature_' . $f . '_short', 'local_clientportal'),
            array_filter(explode(',', (string)$c->features))
        )) ?: '–',
        $c->active ? get_string('yes') : get_string('no'),
        implode(' · ', $links),
    ];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
