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
 * Client Portal - site settings that keep clients apart.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_clientportal\separation;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_clientportal_separation');
require_capability('moodle/site:config', context_system::instance());
$pageurl = new moodle_url('/local/clientportal/admin/separation.php');

if (($fix = optional_param('fix', '', PARAM_ALPHANUMEXT)) && confirm_sesskey()) {
    $n = separation::fix($fix === 'all' ? null : $fix);
    redirect($pageurl, get_string('separation_fixed', 'local_clientportal', $n), null, \core\output\notification::NOTIFY_SUCCESS);
}

$checks = separation::checks();
$open = array_filter($checks, fn($c) => !$c->ok);
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('separation', 'local_clientportal'));
echo html_writer::tag('p', get_string('separation_intro', 'local_clientportal'));
if ($open) {
    echo $OUTPUT->single_button(
        new moodle_url($pageurl, ['fix' => 'all', 'sesskey' => sesskey()]),
        get_string('separation_fixall', 'local_clientportal', count($open)),
        'post',
        ['type' => single_button::BUTTON_PRIMARY]
    );
} else {
    echo $OUTPUT->notification(get_string('separation_allgood', 'local_clientportal'), 'success');
}
$table = new html_table();
$table->attributes['class'] = 'generaltable mt-3';
$table->head = [get_string('keepsetting', 'local_clientportal'), get_string('description'), get_string('status'), ''];
foreach ($checks as $c) {
    $table->data[] = [
        html_writer::tag('strong', s($c->name)),
        s($c->desc),
        $c->ok ? html_writer::span(get_string('separation_ok', 'local_clientportal'), 'badge bg-success badge-success')
            : html_writer::span(get_string('separation_todo', 'local_clientportal'), 'badge bg-warning badge-warning text-dark'),
        $c->ok ? '' : $OUTPUT->single_button(
            new moodle_url($pageurl, ['fix' => $c->key, 'sesskey' => sesskey()]),
            get_string('separation_fix', 'local_clientportal'),
            'post'
        ),
    ];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
