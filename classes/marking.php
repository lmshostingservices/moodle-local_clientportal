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

use moodle_url;

/**
 * Work waiting to be marked in a client's courses: assignment submissions and quiz attempts that need a person.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class marking {
    /**
     * Activities with work waiting, most waiting first.
     *
     * @param scope $scope
     * @param int[] $courseids
     * @return \stdClass[] each: name, typename, coursename, count, oldest, url
     */
    public static function waiting(scope $scope, array $courseids): array {
        global $DB;
        $out = [];
        [$cin, $cparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'mc');
        [$join, $where, $lparams] = $scope->learner_sql('u', 'mk');
        $assignmod = $DB->get_field('modules', 'id', ['name' => 'assign']);
        if ($assignmod) {
            $rows = $DB->get_records_sql(
                "SELECT cm.id AS cmid, a.name, c.fullname AS coursename, COUNT(s.id) AS waiting, MIN(s.timemodified) AS oldest
                   FROM {assign_submission} s
                   JOIN {assign} a ON a.id = s.assignment
                   JOIN {course} c ON c.id = a.course
                   JOIN {course_modules} cm ON cm.instance = a.id AND cm.module = :assignmod AND cm.deletioninprogress = 0
                   JOIN {user} u ON u.id = s.userid
                   $join
              LEFT JOIN {assign_grades} g ON g.assignment = s.assignment AND g.userid = s.userid
                                         AND g.attemptnumber = s.attemptnumber
                  WHERE a.course $cin AND s.latest = 1 AND s.status = 'submitted' AND $where
                    AND (g.id IS NULL OR g.grade IS NULL OR g.grade < 0 OR g.timemodified < s.timemodified)
               GROUP BY cm.id, a.name, c.fullname",
                ['assignmod' => $assignmod] + $cparams + $lparams
            );
            foreach ($rows as $r) {
                $out[] = (object)['name' => $r->name, 'typename' => get_string('modulename', 'assign'),
                    'coursename' => format_string($r->coursename), 'count' => (int)$r->waiting, 'oldest' => (int)$r->oldest,
                    'url' => new moodle_url('/mod/assign/view.php', ['id' => $r->cmid, 'action' => 'grading'])];
            }
        }
        $quizmod = $DB->get_field('modules', 'id', ['name' => 'quiz']);
        if ($quizmod) {
            [$cin2, $cparams2] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'qc');
            [$join2, $where2, $lparams2] = $scope->learner_sql('u', 'qk');
            $rows = $DB->get_records_sql(
                "SELECT cm.id AS cmid, q.name, c.fullname AS coursename, COUNT(qa.id) AS waiting, MIN(qa.timefinish) AS oldest
                   FROM {quiz_attempts} qa
                   JOIN {quiz} q ON q.id = qa.quiz
                   JOIN {course} c ON c.id = q.course
                   JOIN {course_modules} cm ON cm.instance = q.id AND cm.module = :quizmod AND cm.deletioninprogress = 0
                   JOIN {user} u ON u.id = qa.userid
                   $join2
                  WHERE q.course $cin2 AND qa.state = 'finished' AND qa.sumgrades IS NULL AND qa.preview = 0 AND $where2
               GROUP BY cm.id, q.name, c.fullname",
                ['quizmod' => $quizmod] + $cparams2 + $lparams2
            );
            foreach ($rows as $r) {
                $out[] = (object)['name' => $r->name, 'typename' => get_string('modulename', 'quiz'),
                    'coursename' => format_string($r->coursename), 'count' => (int)$r->waiting, 'oldest' => (int)$r->oldest,
                    'url' => new moodle_url('/mod/quiz/report.php', ['id' => $r->cmid, 'mode' => 'grading'])];
            }
        }
        usort($out, fn($a, $b) => [$b->count, $a->oldest] <=> [$a->count, $b->oldest]);
        return $out;
    }
}
