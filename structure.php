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
 * Client Portal - build the client's structure with AI: departments, positions and the competencies each needs.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_clientportal\ai;
use local_clientportal\integration\matrix;
use local_clientportal\scope;
use local_clientportal\structure;
use local_clientportal\ui;

require(__DIR__ . '/../../config.php');

require_login(null, false);
// Access comes from client membership, checked by scope::require_portal(), so no require_capability() here.

$token = optional_param('token', '', PARAM_ALPHANUM);
$action = optional_param('action', '', PARAM_ALPHA);
$scope = scope::require_portal(new moodle_url('/local/clientportal/structure.php', array_filter(['token' => $token])), true);
if (!matrix::available()) {
    redirect($scope->url('/local/clientportal/index.php'));
}
$pageurl = $scope->url('/local/clientportal/structure.php');
$sessionkey = 'local_clientportal_structure';
$stored = $SESSION->{$sessionkey} ?? [];

if ($action && confirm_sesskey() && !$scope->support) {
    if ($action === 'suggest') {
        $business = trim(required_param('business', PARAM_TEXT));
        $people = trim(optional_param('people', '', PARAM_TEXT));
        if ($business === '') {
            redirect(
                $pageurl,
                get_string('structure_needbusiness', 'local_clientportal'),
                null,
                \core\output\notification::NOTIFY_WARNING
            );
        }
        try {
            $suggestion = structure::suggest($scope, $business, $people);
        } catch (moodle_exception $e) {
            redirect($pageurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
        }
        $token = random_string(16);
        $SESSION->{$sessionkey} = [$token => ['clientid' => $scope->id(), 'data' => $suggestion]];
        redirect($scope->url('/local/clientportal/structure.php', ['token' => $token]));
    }
    if ($action === 'create' && isset($stored[$token]) && (int)$stored[$token]['clientid'] === $scope->id()) {
        $suggestion = $stored[$token]['data'];
        $chosen = [];
        foreach ($suggestion as $di => $dept) {
            if (!optional_param("on_{$di}", 0, PARAM_BOOL)) {
                continue;
            }
            $dept['name'] = trim(optional_param("n_{$di}", '', PARAM_TEXT)) ?: $dept['name'];
            $positions = [];
            foreach ($dept['positions'] as $pi => $pos) {
                if (!optional_param("on_{$di}_{$pi}", 0, PARAM_BOOL)) {
                    continue;
                }
                $pos['name'] = trim(optional_param("n_{$di}_{$pi}", '', PARAM_TEXT)) ?: $pos['name'];
                $comps = [];
                foreach ($pos['competencies'] as $ci => $comp) {
                    if (!optional_param("on_{$di}_{$pi}_{$ci}", 0, PARAM_BOOL)) {
                        continue;
                    }
                    $comp['mandatory'] = optional_param("m_{$di}_{$pi}_{$ci}", 0, PARAM_BOOL);
                    $comps[] = $comp;
                }
                $pos['competencies'] = $comps;
                $positions[] = $pos;
            }
            $dept['positions'] = $positions;
            $chosen[] = $dept;
        }
        $counts = structure::create($scope, $chosen);
        unset($SESSION->{$sessionkey});
        redirect(
            $pageurl,
            get_string('structure_created', 'local_clientportal', (object)$counts),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    redirect($pageurl);
}

echo ui::header($scope, 'onboarding', get_string('structure', 'local_clientportal'));

if (!ai::available()) {
    echo ui::card('', html_writer::div(get_string('ai_unavailable', 'local_clientportal'), 'cport-pad'));
    echo ui::footer();
    exit;
}

if ($token && isset($stored[$token]) && (int)$stored[$token]['clientid'] === $scope->id()) {
    // Review the suggestion.
    $f = html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false), 'class' => 'cport-structure']);
    $f .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    $f .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'create']);
    $f .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'token', 'value' => $token]);
    $exists = fn(array $item) => $item['existingid']
        ? ' ' . html_writer::span(get_string('structure_exists', 'local_clientportal'), 'cport-pill cport-pill-notstarted') : '';
    $field = function (string $name, string $value, string $label, string $class) {
        return html_writer::empty_tag('input', ['type' => 'text', 'name' => $name, 'value' => $value,
            'class' => 'form-control form-control-sm ' . $class, 'aria-label' => $label, 'maxlength' => 255]);
    };
    $check = fn(string $name, string $label, bool $on = true) => html_writer::checkbox(
        $name,
        1,
        $on,
        '',
        ['aria-label' => $label, 'class' => 'form-check-input']
    );
    foreach ($stored[$token]['data'] as $di => $dept) {
        $f .= html_writer::start_div('cport-st-dept');
        $f .= html_writer::div($check("on_{$di}", $dept['name']) .
            $field("n_{$di}", $dept['name'], get_string('department', 'local_clientportal'), 'cport-st-deptname') .
            $exists($dept), 'cport-st-row');
        if ($dept['description'] !== '') {
            $f .= html_writer::div(s($dept['description']), 'cport-muted cport-st-desc');
        }
        foreach ($dept['positions'] as $pi => $pos) {
            $f .= html_writer::start_div('cport-st-pos');
            $f .= html_writer::div($check("on_{$di}_{$pi}", $pos['name']) .
                $field("n_{$di}_{$pi}", $pos['name'], get_string('position', 'local_clientportal'), 'cport-st-posname') .
                $exists($pos), 'cport-st-row');
            $rows = '';
            foreach ($pos['competencies'] as $ci => $comp) {
                $base = "{$di}_{$pi}_{$ci}";
                $rows .= html_writer::tag(
                    'li',
                    $check('on_' . $base, $comp['name']) .
                    html_writer::span(s($comp['name']), 'cport-st-compname') .
                    html_writer::span(s($comp['category']), 'cport-tag') .
                    html_writer::tag('label', html_writer::checkbox('m_' . $base, 1, $comp['mandatory'], '', [
                        'class' => 'form-check-input']) . ' ' . get_string('structure_mandatory', 'local_clientportal'), [
                        'class' => 'cport-st-mand']) .
                    $exists($comp) .
                    ($comp['description'] !== '' ? html_writer::div(s($comp['description']), 'cport-muted cport-st-desc') : ''),
                    ['class' => 'cport-st-comp']
                );
            }
            $f .= html_writer::tag('ul', $rows, ['class' => 'cport-st-comps']);
            $f .= html_writer::end_div();
        }
        $f .= html_writer::end_div();
    }
    $f .= html_writer::div(
        html_writer::tag('button', get_string('structure_create', 'local_clientportal'), ['type' => 'submit',
            'class' => 'btn btn-primary']) . ' ' .
        html_writer::link(
            $pageurl,
            get_string('structure_startover', 'local_clientportal'),
            ['class' => 'btn btn-outline-secondary']
        ),
        'cport-st-actions'
    );
    $f .= html_writer::end_tag('form');
    echo ui::card(
        get_string('structure_review', 'local_clientportal'),
        html_writer::div(get_string('structure_reviewhelp', 'local_clientportal'), 'cport-pad cport-muted') .
        html_writer::div($f, 'cport-pad')
    );
    echo ui::footer();
    exit;
}

// Describe the organisation.
$f = html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false), 'class' => 'cport-structure-ask']);
$f .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
$f .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'suggest']);
$f .= html_writer::label(
    get_string('structure_business', 'local_clientportal'),
    'cport-st-business',
    true,
    ['class' => 'cport-field-label']
);
$f .= html_writer::tag('textarea', '', ['name' => 'business', 'id' => 'cport-st-business', 'rows' => 4, 'required' => 'required',
    'maxlength' => 2000, 'class' => 'form-control', 'placeholder' => get_string('structure_business_eg', 'local_clientportal')]);
$f .= html_writer::label(
    get_string('structure_people', 'local_clientportal'),
    'cport-st-people',
    true,
    ['class' => 'cport-field-label mt-3']
);
$f .= html_writer::empty_tag('input', ['type' => 'text', 'name' => 'people', 'id' => 'cport-st-people', 'maxlength' => 60,
    'class' => 'form-control cport-st-peoplefield']);
$f .= html_writer::div(get_string('structure_credits', 'local_clientportal'), 'cport-muted mt-3');
$f .= html_writer::tag('button', get_string('structure_suggest', 'local_clientportal'), ['type' => 'submit',
    'class' => 'btn btn-primary mt-3']);
$f .= html_writer::end_tag('form');
echo ui::card(
    get_string('structure_intro_title', 'local_clientportal'),
    html_writer::div(get_string('structure_intro', 'local_clientportal'), 'cport-pad cport-muted') .
        html_writer::div($f, 'cport-pad')
);
echo ui::footer();
