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
 * Client Portal - connect Xero and see the invoices the portal has drafted.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_clientportal\xero;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_clientportal_xero');
require_capability('moodle/site:config', context_system::instance());
$pageurl = new moodle_url('/local/clientportal/admin/xero.php');
$xero = new xero();

// Back from Xero.
$code = optional_param('code', '', PARAM_NOTAGS);
if ($code !== '') {
    $state = required_param('state', PARAM_ALPHANUM);
    if ($state !== sesskey()) {
        throw new moodle_exception('invalidsesskey');
    }
    try {
        $name = $xero->finish_connect($code);
        redirect(
            $pageurl,
            get_string('xero_connected', 'local_clientportal', s($name)),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        redirect($pageurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}
if (optional_param('error', '', PARAM_ALPHAEXT)) {
    redirect($pageurl, get_string('xero_declined', 'local_clientportal'), null, \core\output\notification::NOTIFY_WARNING);
}
$action = optional_param('action', '', PARAM_ALPHA);
if ($action && confirm_sesskey()) {
    if ($action === 'disconnect') {
        $xero->disconnect();
        redirect($pageurl, get_string('xero_disconnected', 'local_clientportal'));
    }
    if ($action === 'billnow' && $xero->connected()) {
        $lines = $xero->bill_week();
        redirect($pageurl, implode('<br>', array_map('s', $lines)) ?: get_string('xero_noclients', 'local_clientportal'));
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('xero', 'local_clientportal'));
echo html_writer::tag('p', get_string('xero_intro', 'local_clientportal'));
if (!$xero->configured()) {
    echo $OUTPUT->notification(get_string('xero_setup', 'local_clientportal', (object)[
        'url' => xero::redirect_uri()->out(false),
        'settings' => (new moodle_url('/admin/settings.php', ['section' => 'local_clientportal']))->out(false),
    ]), 'info');
} else if (!$xero->connected()) {
    echo html_writer::tag(
        'p',
        get_string('xero_redirect', 'local_clientportal', xero::redirect_uri()->out(false)),
        ['class' => 'text-muted']
    );
    echo html_writer::link($xero->connect_url(), get_string('xero_connect', 'local_clientportal'), ['class' => 'btn btn-primary']);
} else {
    echo $OUTPUT->notification(get_string(
        'xero_connectedto',
        'local_clientportal',
        s(get_config('local_clientportal', 'xerotenantname'))
    ), 'success');
    echo $OUTPUT->single_button(
        new moodle_url($pageurl, ['action' => 'billnow', 'sesskey' => sesskey()]),
        get_string('xero_billnow', 'local_clientportal'),
        'post',
        ['type' => single_button::BUTTON_PRIMARY]
    );
    echo ' ';
    echo $OUTPUT->single_button(
        new moodle_url($pageurl, ['action' => 'disconnect', 'sesskey' => sesskey()]),
        get_string('xero_disconnect', 'local_clientportal'),
        'post'
    );
}

echo $OUTPUT->heading(get_string('xero_invoices', 'local_clientportal'), 3, 'mt-4');
$invoices = $DB->get_records_sql(
    "SELECT i.*, c.name AS clientname
       FROM {local_clientportal_invoice} i
       JOIN {local_clientportal_client} c ON c.id = i.clientid
   ORDER BY i.timemodified DESC",
    [],
    0,
    100
);
if (!$invoices) {
    echo $OUTPUT->notification(get_string('xero_noinvoices', 'local_clientportal'), 'info');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable';
    $table->head = [get_string('clientname', 'local_clientportal'), get_string('xero_number', 'local_clientportal'),
        get_string('xero_period', 'local_clientportal'), get_string('xero_lines', 'local_clientportal'),
        get_string('u_amount', 'local_clientportal'), get_string('status'), get_string('lastmodified')];
    foreach ($invoices as $i) {
        $lines = json_decode((string)$i->lines, true) ?: [];
        $table->data[] = [
            format_string($i->clientname),
            s($i->xeronumber ?: '–'),
            userdate($i->periodstart, get_string('strftimedateshort', 'langconfig')) . ' – ' .
                userdate($i->periodend - 1, get_string('strftimedateshort', 'langconfig')),
            count($lines),
            format_float((float)$i->total, 2),
            $i->status === 'failed' || $i->error ? html_writer::span(
                s($i->error ?: get_string('xero_failedshort', 'local_clientportal')),
                'text-warning'
            ) : get_string('xero_draft', 'local_clientportal'),
            userdate($i->timemodified, get_string('strftimedatetimeshort', 'langconfig')),
        ];
    }
    echo html_writer::table($table);
}
echo $OUTPUT->footer();
