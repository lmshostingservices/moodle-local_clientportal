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
 * Client Portal - which course enrolments a client owns (site administration).
 *
 * A client only sees a learner's progress in courses it owns. Enrolments made while a learner belongs to exactly one
 * client are owned automatically (when that setting is on). Use this page to claim older enrolments, to settle
 * learners who belong to more than one client, or to release an enrolment.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_clientportal\manager;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$clientid = required_param('client', PARAM_INT);
$pageurl = new moodle_url('/local/clientportal/admin/claims.php', ['client' => $clientid]);
admin_externalpage_setup('local_clientportal_clients', '', null, $pageurl);
require_capability('local/clientportal:manageclients', context_system::instance());
$client = manager::get_client($clientid, MUST_EXIST);

if (optional_param('claimall', 0, PARAM_BOOL) && confirm_sesskey()) {
    $n = manager::claim_existing($clientid);
    redirect($pageurl, get_string('claimedcount', 'local_clientportal', $n), null, \core\output\notification::NOTIFY_SUCCESS);
}
if (optional_param('claimselected', 0, PARAM_BOOL) && confirm_sesskey()) {
    $n = 0;
    foreach (optional_param_array('pick', [], PARAM_SEQUENCE) as $pair) {
        [$uid, $cid] = array_map('intval', explode(',', $pair) + [0, 0]);
        if (
            $uid && $cid && \local_clientportal\scope::is_learner($clientid, $uid) &&
                is_enrolled(context_course::instance($cid), $uid) &&
                manager::claim($clientid, $uid, $cid, 'admin')
        ) {
            $n++;
        }
    }
    manager::log($clientid, 'enrolments_claimed', null, (string)$n);
    redirect($pageurl, get_string('claimedcount', 'local_clientportal', $n), null, \core\output\notification::NOTIFY_SUCCESS);
}
if (($release = optional_param('release', 0, PARAM_INT)) && confirm_sesskey()) {
    $claim = $DB->get_record('local_clientportal_enrol', ['id' => $release, 'clientid' => $clientid], '*', MUST_EXIST);
    manager::release_claim((int)$claim->id);
    redirect($pageurl, get_string('released', 'local_clientportal'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
$owned = $DB->get_records_sql("SELECT ce.*, c.fullname AS coursename, u.email, $namefields
                                 FROM {local_clientportal_enrol} ce
                                 JOIN {course} c ON c.id = ce.courseid
                                 JOIN {user} u ON u.id = ce.userid
                                WHERE ce.clientid = :clientid
                             ORDER BY u.lastname, u.firstname, c.fullname", ['clientid' => $clientid]);
$unowned = $DB->get_records_sql(
    "SELECT DISTINCT " . $DB->sql_concat('u.id', "'-'", 'e.courseid') . " AS k,
                                        u.id AS userid, e.courseid, c.fullname AS coursename, u.email, $namefields
                                   FROM {user_enrolments} ue
                                   JOIN {enrol} e ON e.id = ue.enrolid
                                   JOIN {course} c ON c.id = e.courseid
                                   JOIN {user} u ON u.id = ue.userid AND u.deleted = 0
                                   JOIN {cohort_members} cm ON cm.userid = u.id AND cm.cohortid = :cohortid
                              LEFT JOIN {local_clientportal_enrol} ce ON ce.userid = u.id AND ce.courseid = e.courseid
                              LEFT JOIN {local_clientportal_member} m ON m.userid = u.id AND m.clientid = :clientid
                                  WHERE ce.id IS NULL AND m.id IS NULL
                               ORDER BY u.lastname, u.firstname, c.fullname",
    ['cohortid' => $client->cohortid, 'clientid' => $clientid]
);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('ownedfor', 'local_clientportal', format_string($client->name)));
echo html_writer::tag('p', get_string('claimsintro', 'local_clientportal'));

echo $OUTPUT->heading(get_string('unownedenrolments', 'local_clientportal', count($unowned)), 3);
if ($unowned) {
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    $table = new html_table();
    $table->attributes['class'] = 'generaltable';
    $table->head = ['', get_string('learner', 'local_clientportal'), get_string('email'), get_string('course')];
    foreach ($unowned as $u) {
        $table->data[] = [
            html_writer::checkbox(
                'pick[]',
                $u->userid . ',' . $u->courseid,
                false,
                '',
                ['aria-label' => fullname($u) . ' – ' . format_string($u->coursename)]
            ),
            fullname($u), s($u->email), format_string($u->coursename),
        ];
    }
    echo html_writer::table($table);
    echo html_writer::tag(
        'button',
        get_string('claimselected', 'local_clientportal'),
        ['type' => 'submit', 'name' => 'claimselected', 'value' => 1, 'class' => 'btn btn-primary mr-2 me-2']
    );
    echo html_writer::tag(
        'button',
        get_string('claimall', 'local_clientportal', count($unowned)),
        ['type' => 'submit', 'name' => 'claimall', 'value' => 1, 'class' => 'btn btn-secondary']
    );
    echo html_writer::end_tag('form');
} else {
    echo $OUTPUT->notification(get_string('nounowned', 'local_clientportal'), 'info');
}

echo $OUTPUT->heading(get_string('ownedenrolmentscount', 'local_clientportal', count($owned)), 3);
if ($owned) {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable';
    $table->head = [get_string('learner', 'local_clientportal'), get_string('email'), get_string('course'),
        get_string('source', 'local_clientportal'), get_string('since', 'local_clientportal'), ''];
    foreach ($owned as $o) {
        $table->data[] = [
            fullname($o), s($o->email), format_string($o->coursename),
            get_string('source_' . $o->source, 'local_clientportal'),
            userdate($o->timecreated, get_string('strftimedatefullshort', 'langconfig')),
            html_writer::link(
                new moodle_url($pageurl, ['release' => $o->id, 'sesskey' => sesskey()]),
                get_string('release', 'local_clientportal'),
                ['class' => 'text-danger']
            ),
        ];
    }
    echo html_writer::table($table);
}
echo html_writer::link(new moodle_url('/local/clientportal/admin/clients.php'), get_string('backtoclients', 'local_clientportal'));
echo $OUTPUT->footer();
