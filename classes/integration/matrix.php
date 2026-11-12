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

namespace local_clientportal\integration;

use local_clientportal\manager;
use local_clientportal\scope;
use moodle_exception;
use stdClass;

/**
 * Training Matrix for a client: departments, positions, competencies, staff records and compliance.
 *
 * Everything is limited to one client: its own definitions plus shared ones (clientid 0), and its own learners.
 * Nothing here needs a Training Matrix capability: the portal's client membership is the permission, and the
 * client manager only ever touches their own learners.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class matrix {
    /** Competency statuses, best first (same order as Training Matrix). */
    public const STATUSES = ['completed', 'expiring_soon', 'in_progress', 'not_started', 'expired'];

    /** @var bool|null */
    protected static ?bool $available = null;

    /**
     * Is Training Matrix installed with client support?
     *
     * @return bool
     */
    public static function available(): bool {
        global $DB, $CFG;
        if (self::$available === null) {
            $dir = \core_component::get_component_directory('local_trainingmatrix');
            self::$available = false;
            if ($dir && file_exists($dir . '/lib.php')) {
                $columns = $DB->get_manager()->table_exists('local_tm_staff') ? $DB->get_columns('local_tm_staff') : [];
                self::$available = isset($columns['clientid']);
                if (self::$available) {
                    require_once($CFG->dirroot . '/local/trainingmatrix/lib.php');
                }
            }
        }
        return self::$available;
    }

    /**
     * Forget the cached check (tests).
     */
    public static function reset_cache(): void {
        self::$available = null;
    }

    /**
     * SQL for "this client's or shared".
     *
     * @param string $alias
     * @param scope $scope
     * @param string $param
     * @return array [sql, params]
     */
    protected static function owned(string $alias, scope $scope, string $param): array {
        return ["({$alias}.clientid = 0 OR {$alias}.clientid = :{$param})", [$param => $scope->id()]];
    }

    /**
     * Departments the client can use, id => name.
     *
     * @param scope $scope
     * @return array
     */
    public static function departments(scope $scope): array {
        global $DB;
        [$sql, $params] = self::owned('d', $scope, 'dclient');
        $rows = $DB->get_records_sql(
            "SELECT d.id, d.name FROM {local_tm_departments} d WHERE $sql ORDER BY d.sortorder, d.name",
            $params
        );
        return array_map(fn($r) => format_string($r->name), $rows);
    }

    /**
     * Positions the client can use.
     *
     * @param scope $scope
     * @return stdClass[] id => (id, name, departmentid)
     */
    public static function positions(scope $scope): array {
        global $DB;
        [$sql, $params] = self::owned('p', $scope, 'pclient');
        return $DB->get_records_sql(
            "SELECT p.id, p.name, p.departmentid FROM {local_tm_positions} p WHERE $sql ORDER BY p.name",
            $params
        );
    }

    /**
     * Competencies the client's positions use, in display order.
     *
     * @param scope $scope
     * @param int $positionid limit to one position (0 = all of the client's positions)
     * @return stdClass[] id => (id, name, code, category)
     */
    public static function competencies(scope $scope, int $positionid = 0): array {
        global $DB;
        [$sql, $params] = self::owned('p', $scope, 'cclient');
        $where = $positionid ? 'AND p.id = :pid' : '';
        $params['pid'] = $positionid;
        return $DB->get_records_sql(
            "SELECT DISTINCT c.id, c.name, c.code, c.category
               FROM {local_tm_competencies} c
               JOIN {local_tm_pos_comps} pc ON pc.competencyid = c.id
               JOIN {local_tm_positions} p ON p.id = pc.positionid
              WHERE $sql $where
           ORDER BY c.category, c.name",
            $params
        );
    }

    /**
     * Is the position usable by this client?
     *
     * @param scope $scope
     * @param int $positionid
     * @return stdClass|null
     */
    public static function position(scope $scope, int $positionid): ?stdClass {
        $positions = self::positions($scope);
        return $positions[$positionid] ?? null;
    }

    /**
     * Active staff records of the client's learners.
     *
     * @param scope $scope
     * @param int[]|null $userids limit to these learners
     * @return stdClass[] userid => staff record
     */
    public static function staff(scope $scope, ?array $userids = null): array {
        global $DB;
        [$join, $where, $params] = $scope->learner_sql('u', 'st');
        $limit = '';
        if ($userids !== null) {
            if (!$userids) {
                return [];
            }
            [$in, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'stu');
            $limit = "AND u.id $in";
            $params += $inparams;
        }
        $rows = $DB->get_records_sql(
            "SELECT s.*
               FROM {local_tm_staff} s
               JOIN {user} u ON u.id = s.userid
               $join
              WHERE $where AND s.status = 'active' $limit
           ORDER BY s.id",
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            // One active record per person; the newest wins.
            $out[(int)$r->userid] = $r;
        }
        return $out;
    }

    /**
     * Compliance for many learners at once (same rules as local_trainingmatrix_get_staff_compliance()).
     *
     * @param scope $scope
     * @param int[]|null $userids
     * @return array userid => ['staff' => stdClass, 'competencies' => [compid => stdClass status, progress,
     *               completiondate, expirydate], 'stats' => array]
     */
    public static function compliance(scope $scope, ?array $userids = null): array {
        global $DB;
        $staff = self::staff($scope, $userids);
        if (!$staff) {
            return [];
        }
        $positionids = array_unique(array_map(fn($s) => (int)$s->positionid, $staff));
        [$in, $params] = $DB->get_in_or_equal($positionids, SQL_PARAMS_NAMED, 'pos');
        $required = [];
        foreach (
            $DB->get_recordset_sql("SELECT pc.id, pc.positionid, pc.competencyid FROM {local_tm_pos_comps} pc
                                          WHERE pc.positionid $in", $params) as $r
        ) {
            $required[(int)$r->positionid][(int)$r->competencyid] = true;
        }
        [$uin, $uparams] = $DB->get_in_or_equal(array_keys($staff), SQL_PARAMS_NAMED, 'usr');
        $rank = ['completed' => 4, 'expiring_soon' => 3, 'expired' => 2, 'in_progress' => 1, 'not_started' => 0];
        $best = [];
        $rs = $DB->get_recordset_sql("SELECT * FROM {local_tm_completions} WHERE userid $uin", $uparams);
        foreach ($rs as $c) {
            $prev = $best[(int)$c->userid][(int)$c->competencyid] ?? null;
            $crank = $rank[$c->status] ?? 0;
            $prank = $prev ? ($rank[$prev->status] ?? 0) : -1;
            if (!$prev || $crank > $prank || ($crank === $prank && (int)$c->expirydate > (int)$prev->expirydate)) {
                $best[(int)$c->userid][(int)$c->competencyid] = $c;
            }
        }
        $rs->close();

        $now = time();
        $soon = $now + 30 * DAYSECS;
        $out = [];
        foreach ($staff as $userid => $s) {
            $stats = ['total' => 0, 'completed' => 0, 'in_progress' => 0, 'expired' => 0, 'expiring_soon' => 0,
                'not_started' => 0, 'compliance_rate' => 0];
            $comps = [];
            foreach (array_keys($required[(int)$s->positionid] ?? []) as $compid) {
                $stats['total']++;
                $c = $best[$userid][$compid] ?? null;
                $item = (object)['status' => 'not_started', 'progress' => 0, 'completiondate' => null, 'expirydate' => null];
                if ($c) {
                    $item->progress = (int)$c->progress;
                    $item->completiondate = $c->completiondate ? (int)$c->completiondate : null;
                    $item->expirydate = $c->expirydate ? (int)$c->expirydate : null;
                    if (in_array($c->status, ['completed', 'expired', 'expiring_soon'], true)) {
                        if ($c->expirydate && $c->expirydate < $now) {
                            $item->status = 'expired';
                        } else if ($c->expirydate && $c->expirydate < $soon) {
                            $item->status = 'expiring_soon';
                            $stats['completed']++;
                        } else {
                            $item->status = 'completed';
                        }
                    } else if ($c->progress > 0) {
                        $item->status = 'in_progress';
                    }
                }
                // Expiring soon still counts as completed, as in Training Matrix.
                $stats[$item->status]++;
                $comps[$compid] = $item;
            }
            if ($stats['total']) {
                $stats['compliance_rate'] = (int)round($stats['completed'] * 100 / $stats['total']);
            }
            $out[$userid] = ['staff' => $s, 'competencies' => $comps, 'stats' => $stats];
        }
        return $out;
    }

    /**
     * Save a learner's role in the client and (optionally) enrol them in the courses their position requires.
     *
     * @param scope $scope
     * @param int $userid
     * @param stdClass $data positionid, departmentid, startdate, managerid, employeeid
     * @param bool $enrol enrol in required courses
     * @return array ['enrolled' => int courses newly enrolled, 'blocked' => string[] course names]
     */
    public static function save_staff(scope $scope, int $userid, stdClass $data, bool $enrol): array {
        global $DB;
        $scope->require_learner($userid);
        $position = self::position($scope, (int)$data->positionid);
        if (!$position) {
            throw new moodle_exception('error_badposition', 'local_clientportal');
        }
        $departments = self::departments($scope);
        $departmentid = (int)($data->departmentid ?? 0) ?: (int)$position->departmentid;
        if ($departmentid && !isset($departments[$departmentid])) {
            throw new moodle_exception('error_baddepartment', 'local_clientportal');
        }
        $managerid = (int)($data->managerid ?? 0);
        if ($managerid && !array_key_exists($managerid, self::manager_options($scope))) {
            throw new moodle_exception('error_badmanager', 'local_clientportal');
        }
        $now = time();
        $columns = $DB->get_columns('local_tm_staff');
        $record = [
            'userid' => $userid,
            'positionid' => (int)$position->id,
            'departmentid' => $departmentid,
            'startdate' => (int)($data->startdate ?? 0) ?: $now,
            'status' => 'active',
            'managerid' => $managerid,
            'employeeid' => trim((string)($data->employeeid ?? '')),
            'clientid' => $scope->id(),
            'timemodified' => $now,
        ];
        $record = array_intersect_key($record, $columns);
        $transaction = $DB->start_delegated_transaction();
        $existing = $DB->get_record('local_tm_staff', ['userid' => $userid, 'status' => 'active'], '*', IGNORE_MULTIPLE);
        if ($existing) {
            if ((int)($existing->clientid ?? 0) !== 0 && (int)$existing->clientid !== $scope->id()) {
                throw new moodle_exception('error_otherclientstaff', 'local_clientportal');
            }
            $record['id'] = $existing->id;
            $DB->update_record('local_tm_staff', (object)$record);
        } else {
            if (isset($columns['timecreated'])) {
                $record['timecreated'] = $now;
            }
            $DB->insert_record('local_tm_staff', (object)$record);
        }
        $result = ['enrolled' => 0, 'blocked' => []];
        if ($enrol) {
            $result = self::enrol_required($scope, $userid, (int)$position->id);
        }
        $transaction->allow_commit();
        manager::log($scope->id(), 'staff_saved', $userid, format_string($position->name));
        return $result;
    }

    /**
     * Enrol a learner in the courses their position requires and start tracking them.
     *
     * Like local_trainingmatrix_enrol_required_courses(), but a course that cannot take the enrolment is reported
     * back instead of stopping the rest. The client owns every enrolment it makes here.
     *
     * @param scope $scope
     * @param int $userid
     * @param int $positionid
     * @return array ['enrolled' => int, 'blocked' => string[]]
     */
    public static function enrol_required(scope $scope, int $userid, int $positionid): array {
        global $DB;
        $links = $DB->get_records_sql(
            "SELECT cc.* FROM {local_tm_comp_courses} cc
               JOIN {local_tm_pos_comps} pc ON pc.competencyid = cc.competencyid
              WHERE pc.positionid = ?",
            [$positionid]
        );
        $courseids = array_map(fn($l) => (int)$l->courseid, $links);
        $done = enrolment::enrol_courses($scope, $userid, $courseids);
        $ready = array_flip($done['ready']);
        $now = time();
        foreach ($links as $link) {
            if (!isset($ready[(int)$link->courseid])) {
                continue;
            }
            $key = ['userid' => $userid, 'competencyid' => $link->competencyid, 'courseid' => $link->courseid];
            if (!$DB->record_exists('local_tm_completions', $key)) {
                $DB->insert_record('local_tm_completions', (object)($key + [
                    'status' => 'not_started', 'progress' => 0, 'timecreated' => $now, 'timemodified' => $now,
                ]));
            }
        }
        foreach (array_keys($ready) as $courseid) {
            local_trainingmatrix_sync_course_completion($courseid, $userid);
        }
        return ['enrolled' => count($done['enrolled']), 'blocked' => $done['blocked']];
    }

    /**
     * People who can be a learner's direct manager: the client's managers and representatives.
     *
     * @param scope $scope
     * @return array userid => name
     */
    public static function manager_options(scope $scope): array {
        $out = [];
        foreach (manager::get_members($scope->id()) as $m) {
            $out[(int)$m->id] = fullname($m);
        }
        return $out;
    }
}
