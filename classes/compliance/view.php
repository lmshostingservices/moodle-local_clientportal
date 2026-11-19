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

namespace local_clientportal\compliance;

use html_writer;
use local_clientportal\integration\matrix;
use local_clientportal\scope;
use stdClass;

/**
 * Required-training data and display helpers for the portal pages, the learner page and the learner's dashboard.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view {
    /** @var int days ahead that count as "due soon" */
    const SOON = 14;

    /** @var string[] statuses shown */
    const STATUSES = ['overdue', 'duesoon', 'ontrack', 'completed'];

    /**
     * Latest cycle of each learner under each rule of a client.
     *
     * @param scope $scope
     * @param array $filters ruleid, groupid, userid, status
     * @return stdClass[]
     */
    public static function rows(scope $scope, array $filters = []): array {
        global $DB;
        [$join, $where, $params] = $scope->learner_sql('u', 'cv');
        $params['clientid'] = $scope->id();
        $extra = '';
        if (!empty($filters['ruleid'])) {
            $extra .= ' AND d.ruleid = :ruleid';
            $params['ruleid'] = (int)$filters['ruleid'];
        }
        if (!empty($filters['userid'])) {
            $extra .= ' AND d.userid = :userid';
            $params['userid'] = (int)$filters['userid'];
        }
        if (!empty($filters['groupid'])) {
            $extra .= ' AND EXISTS (SELECT 1 FROM {local_clientportal_groupmem} gm WHERE gm.userid = u.id AND gm.groupid = :gid)';
            $params['gid'] = (int)$filters['groupid'];
        }
        $names = \core_user\fields::for_name()->get_sql('u', false, '', '', false);
        $rows = $DB->get_records_sql(
            "SELECT d.*, c.fullname AS coursename, u.email, {$names->selects}
               FROM {local_clientportal_cdue} d
               JOIN {user} u ON u.id = d.userid
               JOIN {course} c ON c.id = d.courseid
               JOIN {local_clientportal_crule} r ON r.id = d.ruleid
               $join
              WHERE $where AND d.clientid = :clientid AND d.status <> 'cancelled' $extra
                AND NOT EXISTS (SELECT 1 FROM {local_clientportal_cdue} d2
                                 WHERE d2.ruleid = d.ruleid AND d2.userid = d.userid AND d2.cycle > d.cycle)
           ORDER BY d.timedue, u.lastname, u.firstname",
            $params + $names->params
        );
        $now = time();
        foreach ($rows as $id => $row) {
            $row->state = self::state($row, $now);
            if (!empty($filters['status']) && $row->state !== $filters['status']) {
                unset($rows[$id]);
            }
        }
        // Overdue first, then due soon, on track, and completed last; soonest first within each.
        $order = array_flip(self::STATUSES);
        uasort($rows, fn($a, $b) => [$order[$a->state], (int)$a->timedue] <=> [$order[$b->state], (int)$b->timedue]);
        return $rows;
    }

    /**
     * overdue, duesoon, ontrack or completed.
     *
     * @param stdClass $row
     * @param int $now
     * @return string
     */
    public static function state(stdClass $row, int $now): string {
        if ($row->status === 'completed') {
            return 'completed';
        }
        if ($now > (int)$row->timedue) {
            return 'overdue';
        }
        return (int)floor(((int)$row->timedue - $now) / DAYSECS) <= self::SOON ? 'duesoon' : 'ontrack';
    }

    /**
     * Coloured status label, with "3 days overdue" or "due in 5 days".
     *
     * @param stdClass $row
     * @return string
     */
    public static function chip(stdClass $row): string {
        $classes = ['overdue' => 'expired', 'duesoon' => 'expiring_soon', 'ontrack' => 'in_progress',
            'completed' => 'completed'];
        $label = get_string('due_' . $row->state, 'local_clientportal');
        $now = time();
        if ($row->state === 'overdue') {
            $days = (int)floor(($now - (int)$row->timedue) / DAYSECS) + 1;
            $label = get_string($days === 1 ? 'due_overdue_day' : 'due_overdue_days', 'local_clientportal', $days);
        } else if ($row->state !== 'completed') {
            $days = (int)floor(((int)$row->timedue - $now) / DAYSECS);
            $label = $days <= 0 ? get_string('due_today', 'local_clientportal')
                : get_string($days === 1 ? 'due_in_day' : 'due_in_days', 'local_clientportal', $days);
        }
        return html_writer::span(s($label), 'cport-chip cport-chip-' . $classes[$row->state]);
    }

    /**
     * Sub-navigation for the compliance pages.
     *
     * @param scope $scope
     * @param string $active due, rules or matrix
     * @return string
     */
    public static function tabs(scope $scope, string $active): string {
        $tabs = ['due' => ['/local/clientportal/compliancedue.php', get_string('ctab_due', 'local_clientportal')]];
        if ($scope->is_manager()) {
            $tabs['rules'] = ['/local/clientportal/compliancerules.php', get_string('ctab_rules', 'local_clientportal')];
        }
        if (matrix::available()) {
            $tabs['matrix'] = ['/local/clientportal/compliance.php', get_string('ctab_matrix', 'local_clientportal')];
        }
        $out = '';
        foreach ($tabs as $key => [$path, $label]) {
            $attrs = ['class' => 'nav-link' . ($key === $active ? ' active' : '')];
            if ($key === $active) {
                $attrs['aria-current'] = 'page';
            }
            $out .= html_writer::tag('li', html_writer::link($scope->url($path), $label, $attrs), ['class' => 'nav-item']);
        }
        return html_writer::tag('ul', $out, ['class' => 'nav nav-tabs cport-subtabs mb-3']);
    }

    /**
     * The signed-in learner's required training that is not complete, soonest first.
     *
     * @param int $userid
     * @return stdClass[]
     */
    public static function mine(int $userid): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT d.id, d.courseid, d.timedue, d.status, c.fullname AS coursename
               FROM {local_clientportal_cdue} d
               JOIN {local_clientportal_crule} r ON r.id = d.ruleid AND r.enabled = 1
               JOIN {course} c ON c.id = d.courseid
              WHERE d.userid = :userid AND d.status = 'open'
           ORDER BY d.timedue",
            ['userid' => $userid],
            0,
            10
        );
        $now = time();
        foreach ($rows as $row) {
            $row->state = self::state($row, $now);
        }
        return $rows;
    }

    /**
     * Hook: a "Required training" box at the top of the learner's dashboard and My courses page.
     *
     * Added as a page notification so it sits at the top of the page content in every theme.
     *
     * @param \core\hook\output\before_http_headers $hook
     */
    public static function before_http_headers(\core\hook\output\before_http_headers $hook): void {
        global $PAGE, $USER;
        if (during_initial_install() || !isloggedin() || isguestuser() || CLI_SCRIPT || AJAX_SCRIPT) {
            return;
        }
        $type = (string)$PAGE->pagetype;
        if (!in_array($type, ['my-index', 'my-courses'], true)) {
            return;
        }
        try {
            $rows = self::mine((int)$USER->id);
        } catch (\dml_exception $e) {
            return;
        }
        if (!$rows) {
            return;
        }
        $items = '';
        $overdue = false;
        foreach ($rows as $row) {
            $overdue = $overdue || $row->state === 'overdue';
            $link = html_writer::link(
                new \moodle_url('/course/view.php', ['id' => $row->courseid]),
                format_string($row->coursename, true, ['context' => \context_course::instance($row->courseid)])
            );
            $items .= html_writer::tag('li', $link . ' ' . self::chip($row) . ' ' .
                html_writer::span(get_string('due_by', 'local_clientportal', userdate(
                    (int)$row->timedue,
                    get_string('strftimedate', 'langconfig')
                )), 'small'));
        }
        $html = html_writer::tag('strong', get_string('due_mine', 'local_clientportal')) .
            html_writer::tag('ul', $items, ['class' => 'list-unstyled mb-0 mt-1 cport-mydue-list']);
        \core\notification::add($html, $overdue ? \core\output\notification::NOTIFY_WARNING
            : \core\output\notification::NOTIFY_INFO);
    }
}
