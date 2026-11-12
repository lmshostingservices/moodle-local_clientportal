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
 * Billable usage per client: AI credits, active learners and new enrolments, with the client's prices.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class usage {
    /** Default credits per Moodle AI action. */
    public const AI_DEFAULTS = ['generate_text' => 1, 'summarise_text' => 1, 'explain_text' => 1, 'generate_image' => 5];

    /**
     * Which client does this usage belong to? A client's own course wins; otherwise the user's only client.
     *
     * @param int $userid
     * @param int $contextid
     * @return int client id or 0
     */
    public static function client_for(int $userid, int $contextid = 0): int {
        global $DB;
        if ($contextid) {
            $context = \context::instance_by_id($contextid, IGNORE_MISSING);
            if ($context && ($coursecontext = $context->get_course_context(false))) {
                if ($owner = courses::owner((int)$coursecontext->instanceid)) {
                    return $owner;
                }
            }
        }
        if ($userid <= 0) {
            return 0;
        }
        $staff = $DB->get_fieldset_select('local_clientportal_member', 'clientid', 'userid = ?', [$userid]);
        if (count($staff) === 1) {
            return (int)reset($staff);
        }
        $sql = "SELECT c.id
                  FROM {local_clientportal_client} c
                  JOIN {cohort_members} cm ON cm.cohortid = c.cohortid AND cm.userid = :userid";
        $clients = $DB->get_fieldset_sql($sql, ['userid' => $userid]);
        return count($clients) === 1 ? (int)reset($clients) : 0;
    }

    /**
     * Record usage. Returns the row id, or 0 when no single client can be found.
     *
     * @param int $userid
     * @param int $contextid
     * @param string $component e.g. mod_contentcreator
     * @param float $quantity e.g. credits
     * @param string $description
     * @param string $kind aicredits
     * @param string $source api|core_ai
     * @param int $sourceid
     * @return int
     */
    public static function record(
        int $userid,
        int $contextid,
        string $component,
        float $quantity,
        string $description = '',
        string $kind = 'aicredits',
        string $source = 'api',
        int $sourceid = 0
    ): int {
        global $DB;
        $clientid = self::client_for($userid, $contextid);
        if (!$clientid || $quantity == 0) {
            return 0;
        }
        return (int)$DB->insert_record('local_clientportal_usage', (object)[
            'clientid' => $clientid, 'userid' => $userid, 'contextid' => $contextid,
            'component' => \core_text::substr($component, 0, 100), 'kind' => $kind, 'quantity' => round($quantity, 2),
            'description' => \core_text::substr($description, 0, 255), 'source' => $source, 'sourceid' => $sourceid,
            'timecreated' => time(),
        ]);
    }

    /**
     * Pick up Moodle AI actions since the last run.
     *
     * @return int actions recorded
     */
    public static function collect_core_ai(): int {
        global $DB;
        if (!$DB->get_manager()->table_exists('ai_action_register')) {
            return 0;
        }
        $last = (int)get_config('local_clientportal', 'lastaiaction');
        $rows = $DB->get_records_select('ai_action_register', 'id > ?', [$last], 'id', '*', 0, 5000);
        $count = 0;
        foreach ($rows as $r) {
            $last = max($last, (int)$r->id);
            if (empty($r->success)) {
                continue;
            }
            $credits = self::ai_credits((string)$r->actionname);
            if (
                self::record(
                    (int)$r->userid,
                    (int)$r->contextid,
                    'core_ai',
                    $credits,
                    get_string('ai_' . $r->actionname, 'local_clientportal'),
                    'aicredits',
                    'core_ai',
                    (int)$r->id
                )
            ) {
                $count++;
            }
        }
        set_config('lastaiaction', $last, 'local_clientportal');
        return $count;
    }

    /**
     * Credits for one Moodle AI action.
     *
     * @param string $action
     * @return float
     */
    public static function ai_credits(string $action): float {
        $setting = get_config('local_clientportal', 'aicredits_' . $action);
        return $setting === false || $setting === '' ? (float)(self::AI_DEFAULTS[$action] ?? 1) : (float)$setting;
    }

    /**
     * Usage per client for a period.
     *
     * @param int $from
     * @param int $to
     * @param int $clientid 0 = all
     * @return stdClass[] clientid => (client, credits, actions, activelearners, newenrolments, completions, lines, total)
     */
    public static function summary(int $from, int $to, int $clientid = 0): array {
        global $DB;
        $clients = $clientid ? [$clientid => manager::get_client($clientid, MUST_EXIST)] : manager::get_clients();
        $out = [];
        foreach ($clients as $c) {
            $credits = $DB->get_record_sql(
                "SELECT COALESCE(SUM(quantity), 0) AS credits, COUNT(1) AS actions
                   FROM {local_clientportal_usage}
                  WHERE clientid = :c AND kind = 'aicredits' AND timecreated >= :f AND timecreated < :t",
                ['c' => $c->id, 'f' => $from, 't' => $to]
            );
            $active = (int)$DB->count_records_sql(
                "SELECT COUNT(DISTINCT l.userid)
                   FROM {logstore_standard_log} l
                   JOIN {cohort_members} cm ON cm.userid = l.userid AND cm.cohortid = :cohort
                  WHERE l.timecreated >= :f AND l.timecreated < :t AND l.courseid > 1
                    AND NOT EXISTS (SELECT 1 FROM {local_clientportal_member} m WHERE m.clientid = :c AND m.userid = l.userid)",
                ['cohort' => $c->cohortid, 'f' => $from, 't' => $to, 'c' => $c->id]
            );
            $enrols = $DB->count_records_select(
                'local_clientportal_enrol',
                'clientid = ? AND timecreated >= ? AND timecreated < ?',
                [$c->id, $from, $to]
            );
            $completions = (int)$DB->count_records_sql(
                "SELECT COUNT(1)
                   FROM {course_completions} cc
                   JOIN {local_clientportal_enrol} e ON e.userid = cc.userid AND e.courseid = cc.course AND e.clientid = :c
                  WHERE cc.timecompleted >= :f AND cc.timecompleted < :t",
                ['c' => $c->id, 'f' => $from, 't' => $to]
            );
            $row = (object)['client' => $c, 'credits' => (float)$credits->credits, 'actions' => (int)$credits->actions,
                'activelearners' => $active, 'newenrolments' => $enrols, 'completions' => $completions];
            $row->lines = self::lines($c, $row, $from, $to);
            $row->total = round(array_sum(array_map(fn($l) => $l['quantity'] * $l['unitamount'], $row->lines)), 2);
            $out[(int)$c->id] = $row;
        }
        return $out;
    }

    /**
     * Invoice lines for a client's usage (only those with a price and a quantity).
     *
     * @param stdClass $client
     * @param stdClass $usage from summary()
     * @param int $from
     * @param int $to
     * @return array[] description, quantity, unitamount
     */
    public static function lines(stdClass $client, stdClass $usage, int $from, int $to): array {
        $period = userdate($from, get_string('strftimedateshort', 'langconfig')) . ' – ' .
            userdate($to - 1, get_string('strftimedateshort', 'langconfig'));
        $lines = [];
        $map = [
            ['ratecredit', $usage->credits, 'line_credits'],
            ['ratelearner', $usage->activelearners, 'line_learners'],
            ['rateenrol', $usage->newenrolments, 'line_enrolments'],
        ];
        foreach ($map as [$rate, $quantity, $string]) {
            if ((float)$client->$rate > 0 && $quantity > 0) {
                $lines[] = ['description' => get_string($string, 'local_clientportal', $period),
                    'quantity' => round((float)$quantity, 2), 'unitamount' => round((float)$client->$rate, 4)];
            }
        }
        return $lines;
    }

    /**
     * Start of this week (Monday 00:00, site time) for a time.
     *
     * @param int $time
     * @return int
     */
    public static function week_start(int $time): int {
        $date = new \DateTime('@' . $time);
        $date->setTimezone(\core_date::get_server_timezone_object());
        $date->modify('monday this week');
        $date->setTime(0, 0);
        return $date->getTimestamp();
    }
}
