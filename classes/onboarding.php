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

use local_clientportal\integration\enrolment;
use local_clientportal\integration\matrix;
use local_clientportal\integration\pathways;

/**
 * Onboarding one learner: role, pathways and course enrolments in one step. Used by the form, the bulk actions
 * and the CSV import, so all three behave the same.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class onboarding {
    /**
     * Apply onboarding to one learner.
     *
     * @param scope $scope
     * @param int $userid
     * @param array $opts positionid (0 = keep), startdate, managerid, employeeid (null = keep), pathways (ids),
     *                    targetdate, enrol (bool), usual (bool: add the pathways usual for the position)
     * @return array ['enrolled' => int, 'blocked' => string[], 'pathways' => string[] names added, 'role' => bool]
     */
    public static function apply(scope $scope, int $userid, array $opts): array {
        $scope->require_learner($userid);
        $out = ['enrolled' => 0, 'blocked' => [], 'pathways' => [], 'role' => false];
        $enrol = enrolment::allowed() && !empty($opts['enrol']);
        $positionid = (int)($opts['positionid'] ?? 0);
        if (matrix::available() && $positionid) {
            $existing = matrix::staff($scope, [$userid])[$userid] ?? null;
            $data = (object)[
                'positionid' => $positionid,
                'startdate' => (int)($opts['startdate'] ?? 0) ?: ($existing ? (int)$existing->startdate : time()),
                'managerid' => array_key_exists('managerid', $opts) && $opts['managerid'] !== null
                    ? (int)$opts['managerid'] : (int)($existing->managerid ?? 0),
                'employeeid' => array_key_exists('employeeid', $opts) && $opts['employeeid'] !== null
                    ? (string)$opts['employeeid'] : (string)($existing->employeeid ?? ''),
            ];
            $result = matrix::save_staff($scope, $userid, $data, $enrol);
            $out['enrolled'] += $result['enrolled'];
            $out['blocked'] = array_merge($out['blocked'], $result['blocked']);
            $out['role'] = true;
        }
        if (pathways::available()) {
            $want = array_map('intval', $opts['pathways'] ?? []);
            if (!empty($opts['usual']) && $positionid) {
                $want = array_merge($want, array_keys(self::usual_pathways($scope, $positionid)));
            }
            $want = array_unique(array_filter($want));
            if ($want) {
                $all = pathways::all($scope);
                $on = [];
                foreach (pathways::assignments($scope, [$userid]) as $a) {
                    if ($a->status === 'active') {
                        $on[(int)$a->pathwayid] = true;
                    }
                }
                foreach ($want as $pathwayid) {
                    if (!isset($all[$pathwayid]) || isset($on[$pathwayid])) {
                        continue;
                    }
                    if ($enrol) {
                        $done = enrolment::enrol_courses($scope, $userid, pathways::course_ids($all[$pathwayid]));
                        $out['enrolled'] += count($done['enrolled']);
                        $out['blocked'] = array_merge($out['blocked'], $done['blocked']);
                    }
                    $target = !empty($opts['targetdate']) ? (int)$opts['targetdate'] : null;
                    pathways::allocate($scope, $pathwayid, $userid, $target);
                    $out['pathways'][] = format_string($all[$pathwayid]->name);
                }
            }
        }
        $out['blocked'] = array_values(array_unique($out['blocked']));
        return $out;
    }

    /**
     * Pathways usual for a position in this client: those at least half of the people in that position have been on.
     *
     * @param scope $scope
     * @param int $positionid
     * @return array pathway id => name
     */
    public static function usual_pathways(scope $scope, int $positionid): array {
        global $DB;
        if (!matrix::available() || !pathways::available() || !$positionid) {
            return [];
        }
        $holders = array_filter(matrix::staff($scope), fn($s) => (int)$s->positionid === $positionid);
        if (!$holders) {
            return [];
        }
        $count = [];
        foreach (pathways::assignments($scope, array_keys($holders)) as $a) {
            $count[(int)$a->pathwayid][(int)$a->userid] = true;
        }
        $all = pathways::all($scope);
        $out = [];
        foreach ($count as $pathwayid => $users) {
            if (isset($all[$pathwayid]) && count($users) * 2 >= count($holders)) {
                $out[$pathwayid] = format_string($all[$pathwayid]->name);
            }
        }
        return $out;
    }

    /**
     * Usual pathways for every position the client uses, for showing on the form.
     *
     * @param scope $scope
     * @return array position id => [pathway id => name]
     */
    public static function usual_map(scope $scope): array {
        $out = [];
        foreach (matrix::available() ? matrix::positions($scope) : [] as $p) {
            if ($usual = self::usual_pathways($scope, (int)$p->id)) {
                $out[(int)$p->id] = $usual;
            }
        }
        return $out;
    }

    /**
     * One line for the notification after onboarding.
     *
     * @param array $result from apply()
     * @return array [message parts, has warnings]
     */
    public static function messages(array $result): array {
        $parts = [];
        if ($result['enrolled']) {
            $parts[] = get_string('onboard_enrolled', 'local_clientportal', $result['enrolled']);
        }
        if ($result['pathways']) {
            $parts[] = get_string('onboard_pathwaysadded', 'local_clientportal', implode(', ', $result['pathways']));
        }
        if ($result['blocked']) {
            $parts[] = get_string('onboard_blocked', 'local_clientportal', implode(', ', $result['blocked']));
        }
        return [$parts, !empty($result['blocked'])];
    }
}
