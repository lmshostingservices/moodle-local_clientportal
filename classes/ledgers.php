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

/**
 * Reads the credit records that LMS Labs AI plugins already keep, and files each charge under the right client.
 *
 * Each entry names a plugin's own table, the column with the credits it was charged, and how to find the activity or
 * course (and so the client). Plugins that are not installed are skipped. Plugin purchases (unlocks) are never read:
 * those are the site buying the plugin, not a client using it.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ledgers {
    /** Rows that are still unfinished after this long are given up on. */
    protected const SETTLE = 2 * DAYSECS;

    /**
     * The known records: table => [component, credits column, user column, where to find the course, time column, done,
     * options]. Options: 'scale' multiplies the credits column (for example seconds to credits) and 'skipcap' leaves out
     * rows of people who have that capability in the activity (such as trainers, who are not billed). 'lookup' reads the
     * user and course from a parent record: [table, key column, user column, course column].
     *
     * @return array[]
     */
    public static function sources(): array {
        return [
            'aisafetyhazards_aireq' => ['mod_aisafetyhazards', 'charged', 'userid', ['module', 'aisafetyhazardsid'],
                'timemodified', "status = 'completed'"],
            'aisoftskills_aireq' => ['mod_aisoftskills', 'charged', 'userid', ['module', 'aisoftskillsid'], 'timemodified',
                "status = 'completed'"],
            'visuallearning_aireq' => ['mod_visuallearning', 'charged', 'userid', ['module', 'visuallearningid'],
                'timemodified', "status = 'completed'"],
            'aispeakwrite_op' => ['mod_aispeakwrite', 'credits', 'userid', ['module', 'aispeakwriteid'], 'timemodified', ''],
            'aiscience_credit' => ['mod_aiscience', 'credits', 'userid', ['course', 'courseid'], 'timecreated', ''],
            'aianatomy_voice' => ['mod_aianatomy', 'credits', null, ['module', 'aianatomyid'], 'timecreated', ''],
            'slideshow_voiceovers' => ['mod_slideshow', 'creditscharged', null, ['module', 'slideshowid'], 'timemodified', ''],
            'aivideoconf_sessions' => ['mod_aivideoconf', 'creditsused', 'hostid', ['course', 'courseid'], 'timecreated', ''],
            'local_fastgrade_aiimage' => ['local_fastgrade', 'credits', 'userid', ['cm', 'cmid'], 'timecreated', ''],
            'assignfeedback_aipdf_usage' => ['assignfeedback_aipdf', 'credits', 'userid', ['assign', 'assignment'],
                'timecreated', ''],
            'local_aicoursebuilder_job' => ['local_aicoursebuilder', 'approvedcredits', null, ['cm', 'cmid'], 'timemodified',
                "status = 'complete'"],
            'local_rtocompliance_autocerts' => ['local_rtocompliance', 'creditcost', 'studentid', ['none', ''],
                'timeprocessed', 'creditdeducted = 1'],
            // Live classes: 1 credit for every 2 learner-hours, once the learner has left. Trainers are never counted.
            'aivirtualclassroom_att' => ['mod_aivirtualclassroom', 'seconds', 'userid', ['module', 'aivirtualclassroomid'],
                'timejoined', 'timeleft > 0', ['scale' => 1 / 7200, 'skipcap' => 'mod/aivirtualclassroom:moderate']],
            // AI Quiz Remedial: one revision module per learner and question; the job says whose and which course.
            'local_aiqr_module' => ['local_aiquizremedial', 'credits_used', null, ['none', ''], 'timecreated', '',
                ['lookup' => ['local_aiqr_job', 'jobid', 'userid', 'courseid']]],
        ];
    }

    /**
     * Read every installed record since the last run.
     *
     * @return int charges filed
     */
    public static function collect(): int {
        global $DB;
        $dbman = $DB->get_manager();
        $count = 0;
        foreach (self::sources() as $table => $def) {
            [$component, $creditcol, $usercol, $where, $timecol, $done] = $def;
            $options = $def[6] ?? [];
            if (!$dbman->table_exists($table)) {
                continue;
            }
            $columns = $DB->get_columns($table);
            if (
                !isset($columns[$creditcol]) || ($usercol && !isset($columns[$usercol])) ||
                    ($where[1] !== '' && !isset($columns[$where[1]])) || !isset($columns[$timecol])
            ) {
                continue;
            }
            $count += self::collect_table($table, $component, $creditcol, $usercol, $where, $timecol, $done, $options);
        }
        return $count;
    }

    /**
     * Read one table.
     *
     * @param string $table
     * @param string $component
     * @param string $creditcol
     * @param string|null $usercol
     * @param array $where [kind, column]
     * @param string $timecol
     * @param string $done SQL that is true once the charge is final ('' = always)
     * @param array $options scale, skipcap
     * @return int
     */
    protected static function collect_table(
        string $table,
        string $component,
        string $creditcol,
        ?string $usercol,
        array $where,
        string $timecol,
        string $done,
        array $options = []
    ): int {
        global $DB;
        $mark = (int)get_config('local_clientportal', 'ledger_' . $table);
        $rows = $DB->get_records_select($table, 'id > ?', [$mark], 'id', '*', 0, 2000);
        if (!$rows) {
            return 0;
        }
        $final = $done === '' ? $rows : $DB->get_records_select(
            $table,
            "id > ? AND id <= ? AND $done",
            [$mark, max(array_keys($rows))],
            'id',
            'id'
        );
        $count = 0;
        $newmark = $mark;
        $waiting = false;
        foreach ($rows as $row) {
            $credits = round((float)($row->$creditcol ?? 0) * (float)($options['scale'] ?? 1), 2);
            $isfinal = isset($final[$row->id]);
            if (!$isfinal && (time() - (int)$row->$timecol) < self::SETTLE) {
                // Not charged yet: look again next time, and keep the mark below it.
                $waiting = true;
            }
            if (
                $isfinal && $credits > 0 &&
                    !$DB->record_exists('local_clientportal_usage', ['source' => $table, 'sourceid' => $row->id])
            ) {
                $contextid = self::context_for($where, $row);
                $userid = $usercol ? (int)$row->$usercol : 0;
                if (!empty($options['lookup'])) {
                    [$ptable, $pkey, $puser, $pcourse] = $options['lookup'];
                    $parent = $DB->get_record($ptable, ['id' => (int)($row->$pkey ?? 0)], "id, $puser, $pcourse");
                    if ($parent) {
                        $userid = (int)$parent->$puser;
                        $contextid = self::context_for(['course', $pcourse], $parent);
                    }
                }
                if (
                    !empty($options['skipcap']) && $userid && $contextid && self::user_has(
                        $options['skipcap'],
                        $contextid,
                        $userid
                    )
                ) {
                    if (!$waiting) {
                        $newmark = (int)$row->id;
                    }
                    continue;
                }
                $name = get_string_manager()->string_exists('pluginname', $component)
                    ? get_string('pluginname', $component) : $component;
                if (usage::record($userid, $contextid, $component, $credits, $name, 'aicredits', $table, (int)$row->id)) {
                    $count++;
                }
            }
            if (!$waiting) {
                $newmark = (int)$row->id;
            }
        }
        set_config('ledger_' . $table, $newmark, 'local_clientportal');
        return $count;
    }

    /**
     * Does the user have the capability in the context (false when the context is gone)?
     *
     * @param string $capability
     * @param int $contextid
     * @param int $userid
     * @return bool
     */
    protected static function user_has(string $capability, int $contextid, int $userid): bool {
        try {
            $context = \context::instance_by_id($contextid, IGNORE_MISSING);
            return $context && get_capability_info($capability) && has_capability($capability, $context, $userid);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The context of the activity or course a row belongs to (0 when unknown).
     *
     * @param array $where [kind, column]
     * @param \stdClass $row
     * @return int
     */
    protected static function context_for(array $where, \stdClass $row): int {
        global $DB;
        [$kind, $column] = $where;
        $value = $column !== '' ? (int)($row->$column ?? 0) : 0;
        if (!$value) {
            return 0;
        }
        try {
            if ($kind === 'course') {
                return (int)\context_course::instance($value, IGNORE_MISSING)?->id;
            }
            if ($kind === 'cm') {
                return (int)\context_module::instance($value, IGNORE_MISSING)?->id;
            }
            $modname = $kind === 'assign' ? 'assign' : preg_replace('/id$/', '', $column);
            $cm = get_coursemodule_from_instance($modname, $value, 0, false, IGNORE_MISSING);
            return $cm ? (int)\context_module::instance($cm->id)->id : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
