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

use moodle_exception;
use stdClass;

/**
 * "Ask about this report": plain-language questions about the progress report, answered by AI from the client's
 * own rows. Learner names never leave the site (they are sent as codes), and the rows shown with the answer are
 * read from the report itself, not from the AI's wording.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class askreport {
    /** Most rows sent with one question. */
    public const MAX_ROWS = 500;

    /**
     * Suggested questions.
     *
     * @return string[]
     */
    public static function suggestions(): array {
        return [
            get_string('ask_q_notstarted', 'local_clientportal'),
            get_string('ask_q_stuck', 'local_clientportal'),
            get_string('ask_q_bycourse', 'local_clientportal'),
            get_string('ask_q_recent', 'local_clientportal'),
        ];
    }

    /**
     * Answer a question about report rows.
     *
     * @param scope $scope
     * @param string $question
     * @param stdClass[] $rows report rows (with fullname and coursename), already limited to the client
     * @param array $groups userid => group names
     * @return stdClass answer (HTML-safe text with names put back), rows (the matching report rows), total, sent
     */
    public static function ask(scope $scope, string $question, array $rows, array $groups): stdClass {
        $question = \core_text::substr(trim($question), 0, 500);
        if ($question === '') {
            throw new moodle_exception('ask_needquestion', 'local_clientportal');
        }
        $rows = array_values($rows);
        $total = count($rows);
        $rows = array_slice($rows, 0, self::MAX_ROWS);
        $codes = [];
        $names = [];
        $lines = ["row,learner,groups,course,status,progress,enrolled,completed,grade,lastaccess"];
        $date = fn($t) => $t ? userdate((int)$t, '%Y-%m-%d', 99, false) : '';
        $csv = fn($v) => '"' . str_replace('"', "'", preg_replace('/\s+/', ' ', (string)$v)) . '"';
        foreach ($rows as $i => $r) {
            $userid = (int)$r->userid;
            if (!isset($codes[$userid])) {
                $codes[$userid] = 'L' . (count($codes) + 1);
                $names[$codes[$userid]] = (string)$r->fullname;
            }
            $lines[] = implode(',', [
                'R' . ($i + 1),
                $codes[$userid],
                $csv(implode('; ', $groups[$userid] ?? [])),
                $csv($r->coursename),
                $r->status,
                $r->percent === null ? '' : (int)$r->percent,
                $date($r->enrolled),
                $date($r->completed),
                $csv($r->grade),
                $date($r->lastaccess),
            ]);
        }
        $prompt = "You answer questions about a training progress report for an organisation's manager.\n" .
            "Today is " . userdate(time(), '%Y-%m-%d', 99, false) . ". Learners are shown as codes (L1, L2, ...); " .
            "always refer to learners by their code. Status is completed, inprogress or notstarted. Progress is a " .
            "percentage. Dates are YYYY-MM-DD; an empty date means none.\n" .
            "Answer only from the rows below. If the rows cannot answer the question, say so. Keep the answer short " .
            "and plain. List the report rows that support the answer.\n" .
            "Reply with JSON only: {\"answer\":\"...\",\"rows\":[\"R1\",\"R2\"]}\n\n" .
            "Question: " . $question . "\n\nRows:\n" . implode("\n", $lines);
        $data = ai::run($scope, 'report_question', $prompt, get_string('ask_usage', 'local_clientportal'));
        if (!isset($data['answer'])) {
            throw new moodle_exception('ask_badanswer', 'local_clientportal');
        }
        // Put names back into the answer, after escaping it.
        $answer = s(\core_text::substr(trim(strip_tags((string)$data['answer'])), 0, 3000));
        $answer = preg_replace_callback('/\bL(\d+)\b/', function ($m) use ($names) {
            return isset($names['L' . $m[1]]) ? s($names['L' . $m[1]]) : $m[0];
        }, $answer);
        $matched = [];
        foreach (array_slice((array)($data['rows'] ?? []), 0, self::MAX_ROWS) as $code) {
            if (preg_match('/^R(\d+)$/', (string)$code, $m) && isset($rows[(int)$m[1] - 1])) {
                $matched[(int)$m[1] - 1] = $rows[(int)$m[1] - 1];
            }
        }
        ksort($matched);
        manager::log($scope->id(), 'report_asked', null, $question);
        return (object)['answer' => $answer, 'rows' => array_values($matched), 'total' => $total, 'sent' => count($rows)];
    }
}
