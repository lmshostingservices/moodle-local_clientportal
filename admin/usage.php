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
 * Client Portal - usage per client (AI credits, active learners, new enrolments) for billing.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_clientportal\usage;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/csvlib.class.php');

admin_externalpage_setup('local_clientportal_usage');
require_capability('local/clientportal:manageclients', context_system::instance());

$period = optional_param('period', 'lastweek', PARAM_ALPHA);
$download = optional_param('download', '', PARAM_ALPHA);
$weekstart = usage::week_start(time());
$monthstart = make_timestamp((int)date('Y'), (int)date('n'), 1);
$prevmonth = make_timestamp((int)date('Y', $monthstart - 1), (int)date('n', $monthstart - 1), 1);
$periods = [
    'thisweek' => [$weekstart, time() + 1],
    'lastweek' => [$weekstart - 7 * DAYSECS, $weekstart],
    'thismonth' => [$monthstart, time() + 1],
    'lastmonth' => [$prevmonth, $monthstart],
];
if (!isset($periods[$period])) {
    $period = 'lastweek';
}
[$from, $to] = $periods[$period];
usage::collect_core_ai();
$rows = usage::summary($from, $to);
$pageurl = new moodle_url('/local/clientportal/admin/usage.php', ['period' => $period]);

if ($download === 'csv') {
    $csv = new csv_export_writer();
    $csv->set_filename('client-usage-' . userdate($from, '%Y-%m-%d'));
    $csv->add_data([get_string('clientname', 'local_clientportal'), get_string('u_credits', 'local_clientportal'),
        get_string('u_actions', 'local_clientportal'), get_string('u_active', 'local_clientportal'),
        get_string('u_enrolments', 'local_clientportal'), get_string('u_completions', 'local_clientportal'),
        get_string('u_amount', 'local_clientportal')]);
    foreach ($rows as $r) {
        $csv->add_data([format_string($r->client->name), $r->credits, $r->actions, $r->activelearners, $r->newenrolments,
            $r->completions, number_format($r->total, 2, '.', '')]);
    }
    $csv->download_file();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('usagereport', 'local_clientportal'));
echo html_writer::tag('p', get_string('usage_intro', 'local_clientportal'));
$links = [];
foreach (array_keys($periods) as $p) {
    $links[] = $p === $period ? html_writer::tag('strong', get_string('period_' . $p, 'local_clientportal'))
        : html_writer::link(new moodle_url($pageurl, ['period' => $p]), get_string('period_' . $p, 'local_clientportal'));
}
echo html_writer::div(implode(' · ', $links) . ' — ' . userdate($from, get_string('strftimedate', 'langconfig')) . ' – ' .
    userdate($to - 1, get_string('strftimedate', 'langconfig')), 'mb-3');

$table = new html_table();
$table->attributes['class'] = 'generaltable';
$table->head = [get_string('clientname', 'local_clientportal'), get_string('u_credits', 'local_clientportal'),
    get_string('u_actions', 'local_clientportal'), get_string('u_active', 'local_clientportal'),
    get_string('u_enrolments', 'local_clientportal'), get_string('u_completions', 'local_clientportal'),
    get_string('u_amount', 'local_clientportal'), get_string('billing', 'local_clientportal')];
$totals = ['credits' => 0, 'actions' => 0, 'active' => 0, 'enrol' => 0, 'comp' => 0, 'amount' => 0];
foreach ($rows as $r) {
    $table->data[] = [
        html_writer::link(
            new moodle_url('/local/clientportal/admin/client.php', ['id' => $r->client->id]),
            format_string($r->client->name)
        ),
        format_float($r->credits, 2, true, true), $r->actions, $r->activelearners, $r->newenrolments, $r->completions,
        format_float($r->total, 2),
        $r->client->billing ? get_string('billing_xero', 'local_clientportal') : get_string('no'),
    ];
    $totals['credits'] += $r->credits;
    $totals['actions'] += $r->actions;
    $totals['active'] += $r->activelearners;
    $totals['enrol'] += $r->newenrolments;
    $totals['comp'] += $r->completions;
    $totals['amount'] += $r->total;
}
$table->data[] = [html_writer::tag('strong', get_string('total')), format_float($totals['credits'], 2, true, true),
    $totals['actions'], $totals['active'], $totals['enrol'], $totals['comp'],
    html_writer::tag('strong', format_float($totals['amount'], 2)), ''];
echo html_writer::table($table);
echo html_writer::div(
    html_writer::link(
        new moodle_url($pageurl, ['download' => 'csv']),
        get_string('exportcsv', 'local_clientportal'),
        ['class' => 'btn btn-secondary']
    ) . ' ' .
    html_writer::link(
        new moodle_url('/local/clientportal/admin/xero.php'),
        get_string('xero', 'local_clientportal'),
        ['class' => 'btn btn-secondary']
    ),
    'mt-2'
);
echo html_writer::tag('p', get_string('usage_howcredits', 'local_clientportal'), ['class' => 'text-muted mt-3']);
echo $OUTPUT->footer();
