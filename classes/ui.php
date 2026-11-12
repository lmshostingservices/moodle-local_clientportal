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

namespace local_clientportal;

use html_writer;
use moodle_url;

/**
 * Portal page shell and small display helpers.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ui {
    /**
     * Page header: branded bar, client switcher, navigation, then the page title.
     *
     * @param scope $scope
     * @param string $active nav key
     * @param string|null $title page title under the bar
     * @param string $actions HTML for buttons next to the title
     * @return string
     */
    public static function header(scope $scope, string $active, ?string $title = null, string $actions = ''): string {
        global $OUTPUT, $USER;
        $nav = [
            'dashboard' => ['/local/clientportal/index.php', get_string('nav_dashboard', 'local_clientportal')],
            'learners' => ['/local/clientportal/learners.php', get_string('nav_learners', 'local_clientportal')],
            'groups' => ['/local/clientportal/groups.php', get_string('nav_groups', 'local_clientportal')],
            'reports' => ['/local/clientportal/report.php', get_string('nav_reports', 'local_clientportal')],
            'certificates' => ['/local/clientportal/certificates.php', get_string('nav_certificates', 'local_clientportal')],
        ];
        if ($scope->is_manager() && (integration\matrix::available() || integration\pathways::available())) {
            $nav['onboarding'] = ['/local/clientportal/onboarding.php', get_string('nav_onboarding', 'local_clientportal')];
        }
        if (integration\matrix::available()) {
            $nav['compliance'] = ['/local/clientportal/compliance.php', get_string('nav_compliance', 'local_clientportal')];
        }
        if (integration\pathways::available()) {
            $nav['pathways'] = ['/local/clientportal/pathways.php', get_string('nav_pathways', 'local_clientportal')];
        }
        if ($scope->is_manager()) {
            $nav['team'] = ['/local/clientportal/team.php', get_string('nav_team', 'local_clientportal')];
        }
        $nav['help'] = ['/local/clientportal/help.php', get_string('nav_help', 'local_clientportal')];
        $items = [];
        foreach ($nav as $key => [$path, $label]) {
            $items[] = ['url' => $scope->url($path)->out(false), 'label' => $label, 'active' => $key === $active];
        }
        $clients = [];
        if (!$scope->support) {
            $memberships = scope::memberships((int)$USER->id);
            if (count($memberships) > 1) {
                foreach ($memberships as $m) {
                    $clients[] = [
                        'url' => (new moodle_url('/local/clientportal/index.php', ['client' => $m->id]))->out(false),
                        'name' => format_string($m->name),
                        'current' => (int)$m->id === $scope->id(),
                    ];
                }
            }
        }
        $context = [
            'clientname' => format_string($scope->client->name),
            'logo' => self::logo_url($scope),
            'homeurl' => $scope->url('/local/clientportal/index.php')->out(false),
            'nav' => $items,
            'clients' => $clients,
            'hasclients' => !empty($clients),
            'username' => fullname($USER),
            'rolename' => get_string('role_' . $scope->role, 'local_clientportal'),
            'logouturl' => (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(false),
            'support' => $scope->support,
            'adminurl' => (new moodle_url('/local/clientportal/admin/clients.php'))->out(false),
        ];
        $bar = $OUTPUT->render_from_template('local_clientportal/header', $context);
        $titlebar = $title === null ? '' : html_writer::div(
            html_writer::tag('h2', s($title), ['class' => 'cport-title']) . html_writer::div($actions, 'cport-actions'),
            'cport-titlebar'
        );
        return $OUTPUT->header() . html_writer::start_div('cport-shell') . $bar .
            html_writer::start_div('cport-main') . $titlebar;
    }

    /**
     * Page footer.
     *
     * @return string
     */
    public static function footer(): string {
        global $OUTPUT;
        return html_writer::end_div() . html_writer::end_div() . $OUTPUT->footer();
    }

    /**
     * Logo for the bar: the client's Cohort Branding logo, else the site logo.
     *
     * @param scope $scope
     * @return string|null
     */
    public static function logo_url(scope $scope): ?string {
        global $OUTPUT;
        if (class_exists('\\local_cohortbranding\\manager')) {
            try {
                $branding = \local_cohortbranding\manager::get_branding_by_cohort((int)$scope->client->cohortid);
                if ($branding && !empty($branding->enabled) && !empty($branding->logourl)) {
                    $url = \local_cohortbranding\manager::validate_url((string)$branding->logourl);
                    if ($url) {
                        return $url;
                    }
                }
            } catch (\Throwable $e) {
                debugging('local_clientportal: branding lookup failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        $url = $OUTPUT->get_compact_logo_url(300, 80) ?: $OUTPUT->get_logo_url(300, 80);
        return $url ? $url->out(false) : null;
    }

    /**
     * A headline number card.
     *
     * @param string $label
     * @param string|int $value
     * @param string $sub
     * @param string $tone neutral|good|warn|accent
     * @return string
     */
    public static function stat(string $label, $value, string $sub = '', string $tone = 'neutral'): string {
        return html_writer::div(
            html_writer::div(s($label), 'cport-stat-label') .
            html_writer::div(s((string)$value), 'cport-stat-value') .
            ($sub !== '' ? html_writer::div(s($sub), 'cport-stat-sub') : ''),
            'cport-stat cport-tone-' . $tone
        );
    }

    /**
     * Status pill for an enrolment.
     *
     * @param string $status report::COMPLETED|INPROGRESS|NOTSTARTED
     * @return string
     */
    public static function status(string $status): string {
        return html_writer::span(get_string('status_' . $status, 'local_clientportal'), 'cport-pill cport-pill-' . $status);
    }

    /**
     * Progress bar.
     *
     * @param int|null $percent
     * @return string
     */
    public static function progress(?int $percent): string {
        if ($percent === null) {
            return html_writer::span(get_string('notracking', 'local_clientportal'), 'text-muted small');
        }
        $bar = html_writer::div(
            html_writer::div('', 'cport-meter-fill', ['style' => 'width:' . max(0, min(100, $percent)) . '%']),
            'cport-meter',
            ['role' => 'progressbar', 'aria-valuenow' => $percent, 'aria-valuemin' => 0, 'aria-valuemax' => 100]
        );
        return html_writer::div($bar . html_writer::span($percent . '%', 'cport-meter-text'), 'cport-progress');
    }

    /**
     * Date, or a dash.
     *
     * @param int|null $time
     * @param bool $withtime
     * @return string
     */
    public static function date(?int $time, bool $withtime = false): string {
        if (!$time) {
            return html_writer::span('–', 'text-muted');
        }
        $format = get_string($withtime ? 'strftimedatetime' : 'strftimedate', 'langconfig');
        return html_writer::tag('time', userdate($time, $format), ['datetime' => date('c', $time)]);
    }

    /**
     * Empty state message.
     *
     * @param string $message
     * @return string
     */
    public static function empty(string $message): string {
        return html_writer::div(s($message), 'cport-empty');
    }

    /**
     * Card wrapper.
     *
     * @param string $title
     * @param string $body
     * @param string $actions
     * @return string
     */
    public static function card(string $title, string $body, string $actions = ''): string {
        $head = $title !== '' || $actions !== ''
            ? html_writer::div(html_writer::tag('h3', s($title), ['class' => 'cport-card-title']) . $actions, 'cport-card-head')
            : '';
        return html_writer::div($head . html_writer::div($body, 'cport-card-body'), 'cport-card');
    }

    /**
     * Group filter dropdown (GET form).
     *
     * @param scope $scope
     * @param moodle_url $url
     * @param int $groupid
     * @param array $extra extra select boxes: name => [options, selected, label]
     * @return string
     */
    public static function filters(scope $scope, moodle_url $url, int $groupid, array $extra = []): string {
        $groups = [0 => get_string('allgroups', 'local_clientportal')];
        foreach (manager::get_groups($scope->id()) as $g) {
            $groups[$g->id] = format_string($g->name);
        }
        $html = html_writer::start_tag('form', ['method' => 'get', 'action' => $url->out_omit_querystring(),
            'class' => 'cport-filters']);
        foreach ($url->params() as $k => $v) {
            if ($k !== 'group' && !array_key_exists($k, $extra)) {
                $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $k, 'value' => $v]);
            }
        }
        if (count($groups) > 1) {
            $html .= html_writer::label(get_string('group', 'local_clientportal'), 'cport-group', true, ['class' => 'sr-only']);
            $html .= html_writer::select(
                $groups,
                'group',
                $groupid,
                false,
                ['id' => 'cport-group', 'class' => 'custom-select form-select', 'onchange' => 'this.form.submit()']
            );
        }
        foreach ($extra as $name => [$options, $selected, $label]) {
            $html .= html_writer::label($label, 'cport-' . $name, true, ['class' => 'sr-only']);
            $html .= html_writer::select(
                $options,
                $name,
                $selected,
                false,
                ['id' => 'cport-' . $name, 'class' => 'custom-select form-select', 'onchange' => 'this.form.submit()']
            );
        }
        $html .= html_writer::end_tag('form');
        return $html;
    }

    /**
     * Competency status chip (Training Matrix). Never red: expired and missing use amber shades.
     *
     * @param string $status completed|expiring_soon|in_progress|not_started|expired
     * @param string $title tooltip, e.g. the expiry date
     * @return string
     */
    public static function chip(string $status, string $title = ''): string {
        $attrs = ['class' => 'cport-chip cport-chip-' . $status];
        if ($title !== '') {
            $attrs['title'] = $title;
        }
        return html_writer::span(get_string('comp_' . $status, 'local_clientportal'), '', $attrs);
    }

    /**
     * Pathway badge: name, percent and an overdue flag.
     *
     * @param scope $scope
     * @param \stdClass $assignment from integration\pathways::assignments()
     * @return string
     */
    public static function pathway_badge(scope $scope, \stdClass $assignment): string {
        $label = format_string($assignment->pathway->name) . ' · ' . $assignment->percent . '%';
        $class = 'cport-pw cport-pw-' . ($assignment->status === 'completed' ? 'done' : ($assignment->overdue ? 'late' : 'open'));
        return html_writer::link(
            $scope->url('/local/clientportal/pathways.php', ['pathway' => $assignment->pathwayid]),
            s($label),
            ['class' => $class]
        );
    }

    /**
     * Name search box that keeps the page's other filters.
     *
     * @param moodle_url $url current page URL with its filters
     * @param string $search
     * @return string
     */
    public static function search(moodle_url $url, string $search): string {
        $html = html_writer::start_tag('form', ['method' => 'get', 'action' => $url->out_omit_querystring(),
            'class' => 'cport-filters', 'role' => 'search']);
        foreach ($url->params() as $k => $v) {
            if ($k !== 'search' && $k !== 'page') {
                $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $k, 'value' => $v]);
            }
        }
        $html .= html_writer::label(get_string('search'), 'cport-namesearch', true, ['class' => 'sr-only']);
        $html .= html_writer::empty_tag('input', ['type' => 'search', 'name' => 'search', 'id' => 'cport-namesearch',
            'value' => $search, 'class' => 'form-control', 'placeholder' => get_string('searchlearners', 'local_clientportal')]);
        $html .= html_writer::end_tag('form');
        return $html;
    }
}
