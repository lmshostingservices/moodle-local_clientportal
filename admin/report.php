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
 * Client Portal - Clients report for the site owner: every client's courses, staff, learners, activity and credits.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_clientportal\sitereport;
use local_clientportal\ui;
use local_clientportal\usage;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/csvlib.class.php');

admin_externalpage_setup('local_clientportal_report');
require_capability('local/clientportal:manageclients', context_system::instance());

$period = optional_param('period', 'thismonth', PARAM_ALPHA);
$fromtext = optional_param('from', '', PARAM_TEXT);
$totext = optional_param('to', '', PARAM_TEXT);
$download = optional_param('download', '', PARAM_ALPHA);
$weekstart = usage::week_start(time());
$monthstart = make_timestamp((int)userdate(time(), '%Y'), (int)userdate(time(), '%m'), 1);
$prevmonth = make_timestamp((int)userdate($monthstart - 1, '%Y'), (int)userdate($monthstart - 1, '%m'), 1);
$periods = [
    'thisweek' => [$weekstart, time() + 1],
    'lastweek' => [$weekstart - WEEKSECS, $weekstart],
    'thismonth' => [$monthstart, time() + 1],
    'lastmonth' => [$prevmonth, $monthstart],
];
if ($period === 'custom' && ($f = strtotime($fromtext)) && ($t = strtotime($totext))) {
    [$from, $to] = [min($f, $t), max($f, $t) + DAYSECS];
} else {
    $period = isset($periods[$period]) ? $period : 'thismonth';
    [$from, $to] = $periods[$period];
}
$params = ['period' => $period] + ($period === 'custom' ? ['from' => $fromtext, 'to' => $totext] : []);
$pageurl = new moodle_url('/local/clientportal/admin/report.php', $params);
['rows' => $rows, 'totals' => $totals] = sitereport::build($from, $to);
$fmt = fn($n) => format_float((float)$n, 2, true, true);
$money = fn($n) => format_float((float)$n, 2);

if ($download === 'csv') {
    $csv = new csv_export_writer();
    $csv->set_filename('clients-report-' . userdate($from, '%Y-%m-%d'));
    $head = ['client', 'active', 'courses', 'categories', 'managers', 'representatives', 'trainers', 'assessors', 'learners',
        'activelearners', 'enrolments', 'newenrolments', 'completions', 'lmslabscredits', 'moodleaicredits', 'amount',
        'pricepercredit', 'xeroinvoice'];
    $csv->add_data($head);
    foreach ($rows as $r) {
        $csv->add_data([format_string($r->client->name), $r->client->active ? 1 : 0, $r->courses, $r->categories, $r->managers,
            $r->reps, $r->trainers, $r->assessors, $r->learners, $r->active, $r->enrolments, $r->newenrolments,
            $r->completions, $r->labscredits, $r->aicredits, number_format($r->amount, 2, '.', ''),
            (float)$r->client->ratecredit, $r->invoice->xeronumber ?? '']);
    }
    $csv->download_file();
    exit;
}

echo $OUTPUT->header();
echo html_writer::start_div('cport-adminreport');
echo html_writer::div(
    html_writer::tag('h2', get_string('clientsreport', 'local_clientportal'), ['class' => 'cport-ar-title']) .
    html_writer::div(
        html_writer::link(
            new moodle_url($pageurl, ['download' => 'csv']),
            get_string('exportcsv', 'local_clientportal'),
            ['class' => 'btn btn-outline-secondary']
        ) . ' ' .
        html_writer::link(
            new moodle_url('/local/clientportal/admin/xero.php'),
            get_string('xero', 'local_clientportal'),
            ['class' => 'btn btn-outline-secondary']
        ) . ' ' .
        html_writer::link(
            new moodle_url('/local/clientportal/admin/clients.php'),
            get_string('clients', 'local_clientportal'),
            ['class' => 'btn btn-outline-secondary']
        ),
        'cport-ar-actions'
    ),
    'cport-ar-head'
);

// Period.
$links = '';
foreach (array_keys($periods) as $p) {
    $links .= html_writer::link(
        new moodle_url('/local/clientportal/admin/report.php', ['period' => $p]),
        get_string('period_' . $p, 'local_clientportal'),
        ['class' => 'cport-ar-chip' . ($p === $period ? ' active' : '')]
    );
}
$custom = html_writer::start_tag('form', ['method' => 'get', 'action' => (new moodle_url('/local/clientportal/admin/report.php'))
    ->out_omit_querystring(), 'class' => 'cport-ar-custom']);
$custom .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'period', 'value' => 'custom']);
$custom .= html_writer::empty_tag('input', ['type' => 'date', 'name' => 'from', 'value' => userdate($from, '%Y-%m-%d', 99, false),
    'class' => 'form-control', 'aria-label' => get_string('r_from', 'local_clientportal')]);
$custom .= html_writer::empty_tag('input', ['type' => 'date', 'name' => 'to', 'value' => userdate($to - 1, '%Y-%m-%d', 99, false),
    'class' => 'form-control', 'aria-label' => get_string('r_to', 'local_clientportal')]);
$custom .= html_writer::tag('button', get_string('show', 'local_clientportal'), ['type' => 'submit',
    'class' => 'btn btn-secondary' . ($period === 'custom' ? ' active' : '')]);
$custom .= html_writer::end_tag('form');
echo html_writer::div(html_writer::div($links, 'cport-ar-chips') . $custom . html_writer::div(
    userdate($from, get_string('strftimedate', 'langconfig')) . ' – ' . userdate($to - 1, get_string(
        'strftimedate',
        'langconfig'
    )),
    'cport-ar-range'
), 'cport-ar-period');

// Cards.
$t = $totals;
echo html_writer::start_div('cport-stats cport-ar-cards');
echo ui::stat(get_string('r_clients', 'local_clientportal'), $t->clients, get_string(
    'r_activeclients',
    'local_clientportal',
    $t->activeclients
), 'accent');
echo ui::stat(get_string('r_courses', 'local_clientportal'), $t->courses, get_string(
    'r_categories',
    'local_clientportal',
    $t->categories
), 'info');
echo ui::stat(get_string('r_trainers', 'local_clientportal'), $t->trainers, get_string(
    'r_staffsub',
    'local_clientportal',
    ['managers' => $t->managers, 'assessors' => $t->assessors]
));
echo ui::stat(get_string('r_learners', 'local_clientportal'), $t->learners, '', 'info');
echo ui::stat(
    get_string('u_active', 'local_clientportal'),
    $t->active,
    get_string('r_inperiod', 'local_clientportal'),
    'info'
);
echo ui::stat(get_string('r_enrolments', 'local_clientportal'), $t->enrolments, get_string(
    'r_newsub',
    'local_clientportal',
    ['new' => $t->newenrolments, 'done' => $t->completions]
));
echo ui::stat(get_string('r_labscredits', 'local_clientportal'), $fmt($t->labscredits), get_string(
    'r_aisub',
    'local_clientportal',
    $fmt($t->aicredits)
), 'warn');
echo ui::stat(get_string('r_toinvoice', 'local_clientportal'), $money($t->amount), get_string(
    'r_toinvoicesub',
    'local_clientportal'
), 'good');
echo html_writer::end_div();

// Charts (plain HTML bars, so they size to the card in any theme).
$labsname = get_string('r_labscredits', 'local_clientportal');
$ainame = get_string('moodleai', 'local_clientportal');
$legend = html_writer::div(
    html_writer::span('', 'cport-ar-key cport-ar-labs') . s($labsname) .
    html_writer::span('', 'cport-ar-key cport-ar-ai') . s($ainame),
    'cport-ar-legend'
);
$hbars = function (array $items) use ($fmt, $labsname, $ainame): string {
    $max = 0;
    foreach ($items as $it) {
        $max = max($max, $it['labs'] + $it['ai']);
    }
    $out = '';
    foreach ($items as $it) {
        $total = $it['labs'] + $it['ai'];
        $wl = $max > 0 ? round($it['labs'] / $max * 100, 2) : 0;
        $wa = $max > 0 ? round($it['ai'] / $max * 100, 2) : 0;
        $bar = html_writer::span('', 'cport-ar-seg cport-ar-labs', ['style' => 'width:' . $wl . '%',
            'title' => $labsname . ': ' . $fmt($it['labs'])]) .
            html_writer::span('', 'cport-ar-seg cport-ar-ai', ['style' => 'width:' . $wa . '%',
            'title' => $ainame . ': ' . $fmt($it['ai'])]);
        $out .= html_writer::div(
            html_writer::div(s($it['name']), 'cport-ar-hname') .
            html_writer::div($bar, 'cport-ar-htrack') .
            html_writer::div($fmt($total), 'cport-ar-hval'),
            'cport-ar-hrow'
        );
    }
    return $out;
};
$charts = '';
if ($rows) {
    $items = [];
    foreach ($rows as $r) {
        $items[] = ['name' => format_string($r->client->name), 'labs' => (float)$r->labscredits,
            'ai' => (float)$r->aicredits];
    }
    usort($items, fn($x, $y) => ($y['labs'] + $y['ai']) <=> ($x['labs'] + $x['ai']));
    $charts .= html_writer::div(html_writer::tag('h3', get_string('r_chart_clients', 'local_clientportal')) .
        $legend . $hbars($items), 'cport-ar-chart');
}
$weekly = sitereport::weekly(8);
$wmax = 0;
foreach ($weekly['labels'] as $i => $label) {
    $wmax = max($wmax, $weekly['labs'][$i] + $weekly['ai'][$i]);
}
$cols = '';
foreach ($weekly['labels'] as $i => $label) {
    $l = $weekly['labs'][$i];
    $ai = $weekly['ai'][$i];
    $hl = $wmax > 0 ? round($l / $wmax * 100, 2) : 0;
    $ha = $wmax > 0 ? round($ai / $wmax * 100, 2) : 0;
    $stack = html_writer::span('', 'cport-ar-vseg cport-ar-ai', ['style' => 'height:' . $ha . '%',
        'title' => $ainame . ': ' . $fmt($ai)]) .
        html_writer::span('', 'cport-ar-vseg cport-ar-labs', ['style' => 'height:' . $hl . '%',
        'title' => $labsname . ': ' . $fmt($l)]);
    $cols .= html_writer::div(
        html_writer::div($fmt($l + $ai), 'cport-ar-vval') .
        html_writer::div($stack, 'cport-ar-vtrack') .
        html_writer::div(s($label), 'cport-ar-vlabel'),
        'cport-ar-vcol'
    );
}
$charts .= html_writer::div(html_writer::tag('h3', get_string('r_chart_weekly', 'local_clientportal')) .
    $legend . html_writer::div($cols, 'cport-ar-vbars'), 'cport-ar-chart');
$plugins = sitereport::by_plugin($from, $to);
if ($plugins) {
    $items = [];
    foreach ($plugins as $name => $credits) {
        $isai = $name === $ainame;
        $items[] = ['name' => $name, 'labs' => $isai ? 0 : (float)$credits, 'ai' => $isai ? (float)$credits : 0];
    }
    $charts .= html_writer::div(html_writer::tag('h3', get_string('r_chart_plugins', 'local_clientportal')) .
        $legend . $hbars($items), 'cport-ar-chart');
}
echo html_writer::div($charts, 'cport-ar-charts');

// Every client.
$table = new html_table();
$table->attributes['class'] = 'generaltable cport-ar-table';
$table->head = [get_string('clientname', 'local_clientportal'), get_string('r_courses', 'local_clientportal'),
    get_string('r_trainers', 'local_clientportal'), get_string('r_learners', 'local_clientportal'),
    get_string('u_active', 'local_clientportal'), get_string('u_enrolments', 'local_clientportal'),
    get_string('u_completions', 'local_clientportal'), get_string('r_labscredits', 'local_clientportal'),
    get_string('moodleai', 'local_clientportal'), get_string('u_amount', 'local_clientportal'),
    get_string('xero_number', 'local_clientportal'), ''];
foreach ($rows as $r) {
    $c = $r->client;
    $name = html_writer::div(format_string($c->name), 'fw-bold font-weight-bold') .
        html_writer::div(implode(' · ', array_filter([
            $c->active ? '' : get_string('inactive', 'local_clientportal'),
            implode(', ', array_map(
                fn($f) => get_string('feature_' . $f . '_short', 'local_clientportal'),
                array_filter(explode(',', (string)$c->features))
            )),
            (float)$c->ratecredit > 0 ? get_string('r_rate', 'local_clientportal', $money($c->ratecredit)) : '',
        ])), 'small text-muted');
    $invoice = $r->invoice ? s($r->invoice->xeronumber ?: get_string('xero_draft', 'local_clientportal')) . html_writer::div(
        $money($r->invoice->total),
        'small text-muted'
    ) : html_writer::span('–', 'text-muted');
    $actions = html_writer::link(
        new moodle_url('/local/clientportal/index.php', ['client' => $c->id]),
        get_string('openportal', 'local_clientportal'),
        ['class' => 'btn btn-sm btn-outline-secondary']
    ) . ' ' .
        html_writer::link(
            new moodle_url('/local/clientportal/admin/usage.php', ['client' => $c->id,
            'period' => in_array($period, ['thisweek', 'lastweek', 'thismonth', 'lastmonth'], true) ? $period : 'thismonth']),
            get_string('r_byplugin', 'local_clientportal'),
            ['class' => 'btn btn-sm btn-outline-secondary']
        );
    $table->data[] = [
        $name,
        $r->courses,
        $r->trainers . html_writer::div(get_string('r_assessorsn', 'local_clientportal', $r->assessors), 'small text-muted'),
        $r->learners,
        $r->active,
        $r->enrolments . html_writer::div(get_string('r_newn', 'local_clientportal', $r->newenrolments), 'small text-muted'),
        $r->completions,
        html_writer::tag('strong', $fmt($r->labscredits)),
        $fmt($r->aicredits),
        html_writer::tag('strong', $money($r->amount)),
        $invoice,
        $actions,
    ];
}
$table->data[] = [html_writer::tag('strong', get_string('total')), $t->courses, $t->trainers, $t->learners, $t->active,
    $t->enrolments, $t->completions, html_writer::tag('strong', $fmt($t->labscredits)), $fmt($t->aicredits),
    html_writer::tag('strong', $money($t->amount)), '', ''];
echo html_writer::div(html_writer::table($table), 'cport-ar-tablewrap');
echo html_writer::tag('p', get_string('r_footnote', 'local_clientportal'), ['class' => 'small text-muted']);
echo html_writer::end_div();
echo $OUTPUT->footer();
