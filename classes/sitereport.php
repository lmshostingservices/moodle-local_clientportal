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

use stdClass;

/**
 * Figures for the site owner's Clients report: every client's courses, staff, learners, activity and credits.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sitereport {
    /**
     * One row per client, plus totals.
     *
     * @param int $from
     * @param int $to
     * @return array [rows => stdClass[], totals => stdClass]
     */
    public static function build(int $from, int $to): array {
        global $DB;
        usage::collect_core_ai();
        ledgers::collect();
        $usage = usage::summary($from, $to);
        $rows = [];
        $keys = ['courses', 'categories', 'managers', 'reps', 'trainers', 'assessors', 'learners', 'active', 'enrolments',
            'newenrolments', 'completions', 'labscredits', 'aicredits', 'amount', 'clients', 'activeclients'];
        $totals = (object)array_fill_keys($keys, 0);
        foreach ($usage as $clientid => $u) {
            $c = $u->client;
            $r = new stdClass();
            $r->client = $c;
            $catids = courses::category_ids($c);
            $r->categories = max(0, count($catids) - 1);
            $r->courses = 0;
            if ($catids) {
                [$in, $params] = $DB->get_in_or_equal($catids);
                $r->courses = $DB->count_records_select('course', "category $in", $params);
            }
            $staff = $DB->get_records('local_clientportal_member', ['clientid' => $clientid], '', 'id, userid, role, assessor');
            $r->managers = count(array_filter($staff, fn($m) => $m->role === manager::ROLE_MANAGER));
            $r->reps = count(array_filter($staff, fn($m) => $m->role === manager::ROLE_REP));
            $r->trainers = count(array_filter($staff, fn($m) => $m->role === manager::ROLE_TRAINER));
            $r->assessors = count(array_filter($staff, fn($m) => !empty($m->assessor)));
            $r->learners = (int)$DB->count_records_sql(
                "SELECT COUNT(1)
                   FROM {cohort_members} cm
                   JOIN {user} u ON u.id = cm.userid AND u.deleted = 0
                  WHERE cm.cohortid = :cohort
                    AND NOT EXISTS (SELECT 1 FROM {local_clientportal_member} m WHERE m.clientid = :c AND m.userid = cm.userid)",
                ['cohort' => $c->cohortid, 'c' => $clientid]
            );
            $r->active = $u->activelearners;
            $r->enrolments = $DB->count_records('local_clientportal_enrol', ['clientid' => $clientid]);
            $r->newenrolments = $u->newenrolments;
            $r->completions = $u->completions;
            $split = $DB->get_records_sql_menu(
                "SELECT CASE WHEN component = 'core_ai' THEN 'ai' ELSE 'labs' END AS k, SUM(quantity)
                   FROM {local_clientportal_usage}
                  WHERE clientid = :c AND kind = 'aicredits' AND timecreated >= :f AND timecreated < :t
               GROUP BY CASE WHEN component = 'core_ai' THEN 'ai' ELSE 'labs' END",
                ['c' => $clientid, 'f' => $from, 't' => $to]
            );
            $r->labscredits = (float)($split['labs'] ?? 0);
            $r->aicredits = (float)($split['ai'] ?? 0);
            $r->amount = $u->total;
            $r->invoice = $DB->get_records(
                'local_clientportal_invoice',
                ['clientid' => $clientid],
                'timemodified DESC',
                '*',
                0,
                1
            );
            $r->invoice = $r->invoice ? reset($r->invoice) : null;
            foreach ($keys as $k) {
                if (isset($r->$k)) {
                    $totals->$k += $r->$k;
                }
            }
            $totals->clients++;
            $totals->activeclients += $c->active ? 1 : 0;
            $rows[$clientid] = $r;
        }
        uasort($rows, fn($a, $b) => [$b->labscredits + $b->aicredits, $b->learners] <=> [$a->labscredits + $a->aicredits,
            $a->learners]);
        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * Credits per week for the last weeks, LMS Labs and Moodle AI apart.
     *
     * @param int $weeks
     * @return array [labels => string[], labs => float[], ai => float[]]
     */
    public static function weekly(int $weeks = 8): array {
        global $DB;
        $start = usage::week_start(time()) - ($weeks - 1) * WEEKSECS;
        $out = ['labels' => [], 'labs' => [], 'ai' => []];
        for ($i = 0; $i < $weeks; $i++) {
            $from = $start + $i * WEEKSECS;
            $to = $from + WEEKSECS;
            $split = $DB->get_records_sql_menu(
                "SELECT CASE WHEN component = 'core_ai' THEN 'ai' ELSE 'labs' END AS k, SUM(quantity)
                   FROM {local_clientportal_usage}
                  WHERE kind = 'aicredits' AND timecreated >= :f AND timecreated < :t
               GROUP BY CASE WHEN component = 'core_ai' THEN 'ai' ELSE 'labs' END",
                ['f' => $from, 't' => $to]
            );
            $out['labels'][] = userdate($from, '%d %b', 99, false);
            $out['labs'][] = round((float)($split['labs'] ?? 0), 2);
            $out['ai'][] = round((float)($split['ai'] ?? 0), 2);
        }
        return $out;
    }

    /**
     * Credits by plugin in the period, most first.
     *
     * @param int $from
     * @param int $to
     * @return array name => credits
     */
    public static function by_plugin(int $from, int $to): array {
        global $DB;
        $rows = $DB->get_records_sql_menu(
            "SELECT component, SUM(quantity)
               FROM {local_clientportal_usage}
              WHERE kind = 'aicredits' AND timecreated >= :f AND timecreated < :t
           GROUP BY component",
            ['f' => $from, 't' => $to]
        );
        arsort($rows);
        $out = [];
        foreach ($rows as $component => $credits) {
            $name = $component === 'core_ai' ? get_string('moodleai', 'local_clientportal')
                : (get_string_manager()->string_exists('pluginname', $component) ? get_string('pluginname', $component)
                : (string)($DB->get_field_sql(
                    "SELECT MAX(description) FROM {local_clientportal_usage} WHERE component = ?",
                    [$component]
                ) ?: $component));
            $out[$name] = round((float)$credits, 2);
        }
        return $out;
    }
}
