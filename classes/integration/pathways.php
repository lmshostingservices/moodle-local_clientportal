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
 * Training Pathways for a client: the client's own and shared pathways, who is on them, how far each person has got,
 * and the manager sign-offs waiting.
 *
 * Client membership is the permission. A client only sees its own learners, and only pathways that are its own or
 * shared (clientid 0).
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pathways {
    /** @var bool|null */
    protected static ?bool $available = null;

    /**
     * Is Training Pathways installed with client support?
     *
     * @return bool
     */
    public static function available(): bool {
        global $DB, $CFG;
        if (self::$available === null) {
            $dir = \core_component::get_component_directory('local_trainingpathways');
            self::$available = false;
            if ($dir && file_exists($dir . '/lib.php') && $DB->get_manager()->table_exists('local_tp_pathways')) {
                self::$available = isset($DB->get_columns('local_tp_pathways')['clientid']);
                if (self::$available) {
                    require_once($CFG->dirroot . '/local/trainingpathways/lib.php');
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
     * Active pathways the client can use, with their stages and tasks.
     *
     * @param scope $scope
     * @return stdClass[] id => pathway (->stages: id => stage (->tasks: id => task))
     */
    public static function all(scope $scope): array {
        global $DB;
        $pathways = $DB->get_records_select(
            'local_tp_pathways',
            "status = 'active' AND (clientid = 0 OR clientid = :client)",
            ['client' => $scope->id()],
            'sortorder, name'
        );
        if (!$pathways) {
            return [];
        }
        foreach ($pathways as $p) {
            $p->stages = [];
        }
        [$in, $params] = $DB->get_in_or_equal(array_keys($pathways), SQL_PARAMS_NAMED, 'pw');
        $stages = $DB->get_records_select('local_tp_stages', "pathwayid $in", $params, 'sortorder, id');
        foreach ($stages as $s) {
            $s->tasks = [];
            $pathways[$s->pathwayid]->stages[$s->id] = $s;
        }
        if ($stages) {
            [$sin, $sparams] = $DB->get_in_or_equal(array_keys($stages), SQL_PARAMS_NAMED, 'st');
            foreach ($DB->get_records_select('local_tp_tasks', "stageid $sin", $sparams, 'sortorder, id') as $t) {
                $stage = $stages[$t->stageid];
                $pathways[$stage->pathwayid]->stages[$stage->id]->tasks[$t->id] = $t;
            }
        }
        return $pathways;
    }

    /**
     * The client's learners' assignments (active and completed) with progress, current stage and sign-offs waiting.
     *
     * @param scope $scope
     * @param int[]|null $userids limit to these learners
     * @param int $pathwayid limit to one pathway
     * @return stdClass[] assignment id => assignment with ->pathway, ->total, ->complete, ->percent, ->stage,
     *                    ->overdue, ->signoffs (task records waiting in the current stage)
     */
    public static function assignments(scope $scope, ?array $userids = null, int $pathwayid = 0): array {
        global $DB;
        $pathways = self::all($scope);
        if (!$pathways || ($pathwayid && !isset($pathways[$pathwayid]))) {
            return [];
        }
        [$join, $where, $params] = $scope->learner_sql('u', 'pa');
        [$pin, $pparams] = $DB->get_in_or_equal($pathwayid ? [$pathwayid] : array_keys($pathways), SQL_PARAMS_NAMED, 'pap');
        $params += $pparams;
        $limit = '';
        if ($userids !== null) {
            if (!$userids) {
                return [];
            }
            [$uin, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'pau');
            $limit = "AND u.id $uin";
            $params += $uparams;
        }
        $rows = $DB->get_records_sql(
            "SELECT a.*
               FROM {local_tp_assignments} a
               JOIN {user} u ON u.id = a.userid
               $join
              WHERE $where AND a.pathwayid $pin AND a.status IN ('active', 'completed') $limit
           ORDER BY a.timeassigned DESC, a.id DESC",
            $params
        );
        if (!$rows) {
            return [];
        }
        [$ain, $aparams] = $DB->get_in_or_equal(array_keys($rows), SQL_PARAMS_NAMED, 'paa');
        $done = [];
        $rs = $DB->get_recordset_select('local_tp_progress', "assignmentid $ain", $aparams, '', 'id, assignmentid, taskid, status');
        foreach ($rs as $p) {
            $done[(int)$p->assignmentid][(int)$p->taskid] = $p->status;
        }
        $rs->close();
        $now = time();
        foreach ($rows as $a) {
            $pathway = $pathways[$a->pathwayid];
            $a->pathway = $pathway;
            $a->total = 0;
            $a->complete = 0;
            $a->stage = null;
            $a->signoffs = [];
            foreach ($pathway->stages as $stage) {
                $open = false;
                foreach ($stage->tasks as $task) {
                    $status = $done[(int)$a->id][(int)$task->id] ?? 'notstarted';
                    if ($task->required) {
                        $a->total++;
                        if ($status === 'complete') {
                            $a->complete++;
                        } else {
                            $open = true;
                        }
                    }
                }
                if ($open && $a->stage === null) {
                    $a->stage = $stage;
                }
            }
            if ($a->status === 'active') {
                // Sign-offs waiting: in the current stage for a sequential pathway, anywhere otherwise.
                foreach ($pathway->stages as $stage) {
                    if ($pathway->sequential && $a->stage && (int)$stage->id !== (int)$a->stage->id) {
                        continue;
                    }
                    foreach ($stage->tasks as $task) {
                        if ($task->tasktype === 'signoff' && ($done[(int)$a->id][(int)$task->id] ?? '') !== 'complete') {
                            $a->signoffs[(int)$task->id] = $task;
                        }
                    }
                }
            }
            $a->percent = $a->total ? (int)round($a->complete * 100 / $a->total) : ($a->status === 'completed' ? 100 : 0);
            $a->overdue = $a->status === 'active' && !empty($a->targetdate) && (int)$a->targetdate < $now;
        }
        return $rows;
    }

    /**
     * Put a learner on a pathway (or return their current active assignment).
     *
     * @param scope $scope
     * @param int $pathwayid
     * @param int $userid
     * @param int|null $targetdate
     * @return int assignment id
     */
    public static function allocate(scope $scope, int $pathwayid, int $userid, ?int $targetdate): int {
        global $DB, $USER;
        $scope->require_learner($userid);
        $pathways = self::all($scope);
        if (!isset($pathways[$pathwayid])) {
            throw new moodle_exception('error_badpathway', 'local_clientportal');
        }
        $lock = self::lock('allocate_' . $pathwayid . '_' . $userid);
        try {
            $existing = $DB->get_record(
                'local_tp_assignments',
                ['pathwayid' => $pathwayid, 'userid' => $userid, 'status' => 'active'],
                'id',
                IGNORE_MULTIPLE
            );
            if ($existing) {
                return (int)$existing->id;
            }
            $assignmentid = (int)$DB->insert_record('local_tp_assignments', (object)[
                'pathwayid' => $pathwayid, 'userid' => $userid, 'assignedby' => (int)$USER->id,
                'timeassigned' => time(), 'targetdate' => $targetdate ?: null, 'status' => 'active',
                'timemodified' => time(),
            ]);
        } finally {
            $lock->release();
        }
        // Courses already completed count straight away (the completion event will not fire again).
        foreach (self::course_ids($pathways[$pathwayid]) as $courseid) {
            \local_trainingpathways\progress_service::course_completed($assignmentid, $courseid, $userid);
        }
        manager::log($scope->id(), 'pathway_assigned', $userid, format_string($pathways[$pathwayid]->name));
        return $assignmentid;
    }

    /**
     * Courses used by a pathway's course and quiz tasks.
     *
     * @param stdClass $pathway from pathways()
     * @return int[]
     */
    public static function course_ids(stdClass $pathway): array {
        $ids = [];
        foreach ($pathway->stages as $stage) {
            foreach ($stage->tasks as $task) {
                if (in_array($task->tasktype, ['course', 'quiz'], true) && (int)$task->courseid > 0) {
                    $ids[(int)$task->courseid] = (int)$task->courseid;
                }
            }
        }
        return array_values($ids);
    }

    /**
     * May client managers sign off on this site?
     *
     * @return bool
     */
    public static function signoff_allowed(): bool {
        return (bool)get_config('local_clientportal', 'managersignoff');
    }

    /**
     * A client manager signs off a task for one of their learners.
     *
     * @param scope $scope
     * @param int $assignmentid
     * @param int $taskid
     * @return array progress ['total', 'complete', 'percent']
     */
    public static function sign_off(scope $scope, int $assignmentid, int $taskid): array {
        global $DB, $USER;
        if (!$scope->is_manager() || !self::signoff_allowed()) {
            throw new moodle_exception('error_managersonly', 'local_clientportal');
        }
        $assignment = $DB->get_record('local_tp_assignments', ['id' => $assignmentid], '*', MUST_EXIST);
        $scope->require_learner((int)$assignment->userid);
        $pathways = self::all($scope);
        $pathway = $pathways[$assignment->pathwayid] ?? null;
        $task = null;
        foreach ($pathway ? $pathway->stages : [] as $stage) {
            $task = $task ?? ($stage->tasks[$taskid] ?? null);
        }
        if (
            !$pathway || !$task || $task->tasktype !== 'signoff' || $assignment->status !== 'active'
                || (int)$assignment->userid === (int)$USER->id
        ) {
            throw new moodle_exception('error_badsignoff', 'local_clientportal');
        }
        $lock = self::lock('assignment_' . $assignmentid);
        try {
            $transaction = $DB->start_delegated_transaction();
            $assignment = $DB->get_record('local_tp_assignments', ['id' => $assignmentid], '*', MUST_EXIST);
            if ($assignment->status !== 'active') {
                throw new moodle_exception('error_badsignoff', 'local_clientportal');
            }
            $record = (object)[
                'assignmentid' => $assignmentid, 'taskid' => $taskid, 'userid' => $assignment->userid,
                'status' => 'complete', 'signedoffby' => (int)$USER->id, 'timemodified' => time(),
            ];
            if ($existing = $DB->get_record('local_tp_progress', ['assignmentid' => $assignmentid, 'taskid' => $taskid])) {
                $record->id = $existing->id;
                $DB->update_record('local_tp_progress', $record);
            } else {
                $DB->insert_record('local_tp_progress', $record);
            }
            $progress = local_trainingpathways_assignment_progress($assignmentid);
            if ($progress['total'] > 0 && $progress['complete'] === $progress['total']) {
                $DB->update_record('local_tp_assignments', (object)[
                    'id' => $assignmentid, 'status' => 'completed', 'timemodified' => time(),
                ]);
            }
            $transaction->allow_commit();
        } finally {
            $lock->release();
        }
        manager::log($scope->id(), 'pathway_signoff', (int)$assignment->userid, format_string($task->name));
        return $progress;
    }

    /**
     * Take the same lock Training Pathways uses, so portal changes never race the plugin's own.
     *
     * @param string $key
     * @return \core\lock\lock
     */
    protected static function lock(string $key): \core\lock\lock {
        $factory = \core\lock\lock_config::get_lock_factory('local_trainingpathways');
        $lock = $factory->get_lock($key, 10);
        if (!$lock) {
            throw new moodle_exception('error:locktimeout', 'local_trainingpathways');
        }
        return $lock;
    }
}
