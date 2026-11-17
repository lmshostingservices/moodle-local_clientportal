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
 * Course catalogue for learners: every course from their organisations and the courses open to join.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_clientportal\catalogue;

require(__DIR__ . '/../../config.php');

require_login(null, false);
if (isguestuser()) {
    throw new require_login_exception('guestsarenotallowed');
}
// Every signed-in person may browse their own catalogue; what they see is limited to their organisations.

$search = trim(optional_param('search', '', PARAM_TEXT));
$sort = optional_param('sort', 'newest', PARAM_ALPHA);
$sort = in_array($sort, catalogue::SORTS, true) ? $sort : 'newest';
$view = optional_param('view', 'tiles', PARAM_ALPHA) === 'list' ? 'list' : 'tiles';

$url = new moodle_url('/local/clientportal/catalogue.php', ['search' => $search, 'sort' => $sort, 'view' => $view]);
$PAGE->set_url($url);
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('catalogue', 'local_clientportal'));
$PAGE->set_heading(get_string('catalogue', 'local_clientportal'));

$courses = catalogue::courses((int)$USER->id, $search, $sort);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('catalogue', 'local_clientportal'));

// Search, sort and view.
$form = html_writer::start_tag('form', ['method' => 'get', 'action' => $url->out_omit_querystring(), 'class' => 'cport-cat-tools']);
$form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'view', 'value' => $view]);
$form .= html_writer::label(get_string('search'), 'cport-cat-search', true, ['class' => 'visually-hidden sr-only']);
$form .= html_writer::empty_tag('input', ['type' => 'search', 'name' => 'search', 'id' => 'cport-cat-search', 'value' => $search,
    'class' => 'form-control', 'placeholder' => get_string('catalogue_search', 'local_clientportal')]);
$sorts = [];
foreach (catalogue::SORTS as $key) {
    $sorts[$key] = get_string('catalogue_sort_' . $key, 'local_clientportal');
}
$form .= html_writer::label(
    get_string('catalogue_sort', 'local_clientportal'),
    'cport-cat-sort',
    true,
    ['class' => 'visually-hidden sr-only']
);
$form .= html_writer::select($sorts, 'sort', $sort, false, ['id' => 'cport-cat-sort', 'class' => 'form-select custom-select',
    'onchange' => 'this.form.submit()']);
$form .= html_writer::tag('button', get_string('search'), ['type' => 'submit', 'class' => 'btn btn-primary']);
$toggle = '';
foreach (['tiles', 'list'] as $mode) {
    $toggle .= html_writer::link(
        new moodle_url($url, ['view' => $mode]),
        get_string('catalogue_view_' . $mode, 'local_clientportal'),
        ['class' => 'btn btn-sm ' . ($mode === $view ? 'btn-secondary' : 'btn-outline-secondary'),
            'aria-pressed' => $mode === $view ? 'true' : 'false']
    );
}
$form .= html_writer::div($toggle, 'btn-group cport-cat-toggle', ['role' => 'group',
    'aria-label' => get_string('catalogue_view', 'local_clientportal')]);
$form .= html_writer::end_tag('form');
echo $form;

if (!$courses) {
    echo $OUTPUT->notification(
        get_string($search === '' ? 'catalogue_empty' : 'catalogue_nomatch', 'local_clientportal'),
        'info',
        false
    );
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::div(\local_clientportal\ui::count('catalogue_count', count($courses)), 'cport-muted mb-2');
$items = '';
foreach ($courses as $c) {
    $image = $c->image
        ? html_writer::empty_tag('img', ['src' => $c->image, 'alt' => '', 'class' => 'cport-cat-img'])
        : html_writer::div(s(core_text::substr($c->fullname, 0, 1)), 'cport-cat-img cport-cat-noimg', ['aria-hidden' => 'true']);
    $meta = [];
    if ($c->clientname !== '') {
        $meta[] = html_writer::span(format_string($c->clientname), 'cport-tag');
    }
    if ($c->enrolled) {
        $meta[] = html_writer::span(get_string('catalogue_enrolled', 'local_clientportal'), 'cport-pill cport-pill-completed');
    } else if ($c->pricelabel !== '') {
        $meta[] = html_writer::span(s($c->pricelabel), 'cport-pill cport-pill-inprogress');
    }
    $label = get_string(
        $c->enrolled ? 'catalogue_open' : ($c->canjoin ? 'catalogue_join' : 'catalogue_details'),
        'local_clientportal'
    );
    $body = html_writer::tag('h3', html_writer::link($c->url, s($c->fullname)), ['class' => 'cport-cat-name']) .
        html_writer::div(implode(' ', $meta), 'cport-cat-meta') .
        html_writer::tag('p', s($c->summary), ['class' => 'cport-cat-summary']) .
        html_writer::link($c->url, $label, ['class' => 'btn btn-sm ' . ($c->enrolled ? 'btn-outline-primary' : 'btn-primary'),
            'aria-label' => $label . ': ' . $c->fullname]);
    $items .= html_writer::tag('li', $image . html_writer::div($body, 'cport-cat-body'), ['class' => 'cport-cat-item']);
}
echo html_writer::tag('ul', $items, ['class' => 'cport-cat cport-cat-' . $view]);
echo $OUTPUT->footer();
