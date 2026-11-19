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

use local_clientportal\integration\matrix;
use local_clientportal\manager;
use local_clientportal\scope;
use moodle_url;
use stdClass;

/**
 * Compliance messages: to learners (assigned, renew, reminder, due today, overdue) and to managers (who is overdue).
 * Sent through Moodle messaging, so learners can choose email or notifications. Messages come from the client's name.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notifier {
    /**
     * Message a learner.
     *
     * @param scope $scope
     * @param stdClass $rule
     * @param stdClass $row the cycle
     * @param string $kind assigned, renew, reminder, today or overdue
     * @param int $now
     * @return bool sent
     */
    public static function learner(scope $scope, stdClass $rule, stdClass $row, string $kind, int $now): bool {
        $user = \core_user::get_user((int)$row->userid);
        if (!$user || $user->deleted || $user->suspended) {
            return false;
        }
        $url = new moodle_url('/course/view.php', ['id' => $row->courseid]);
        $days = (int)ceil(((int)$row->timedue - $now) / DAYSECS);
        $a = (object)[
            'client' => format_string($scope->client->name),
            'name' => $user->firstname,
            'course' => rules::course_name((int)$row->courseid),
            'due' => userdate(
                (int)$row->timedue,
                get_string('strftimedate', 'langconfig'),
                \core_date::get_user_timezone($user)
            ),
            'days' => max(0, $days),
            'url' => $url->out(false),
        ];
        $key = $kind === 'reminder' && $days === 1 ? 'reminder1' : $kind;
        $subject = get_string('msg_' . $key . '_subject', 'local_clientportal', $a);
        $body = get_string('msg_' . $key . '_body', 'local_clientportal', $a);
        $extra = trim((string)$rule->message);
        $text = $body . ($extra !== '' ? "\n\n" . $extra : '') . "\n\n" .
            get_string('msg_open', 'local_clientportal', $a);
        $html = \html_writer::tag('p', s($body)) .
            ($extra !== '' ? \html_writer::tag('p', nl2br(s($extra))) : '') .
            \html_writer::tag('p', \html_writer::link($url, get_string('msg_button', 'local_clientportal', $a)));
        return self::send($scope, $user, $subject, $text, $html, $url, (int)$row->courseid);
    }

    /**
     * Tell managers who is overdue: the learner's manager in Training Matrix, otherwise the client's managers.
     *
     * @param scope $scope
     * @param stdClass $rule
     * @param stdClass[] $rows overdue cycles
     * @param int $now
     * @return int managers told
     */
    public static function managers(scope $scope, stdClass $rule, array $rows, int $now): int {
        $userids = array_map(fn($r) => (int)$r->userid, $rows);
        $staff = matrix::available() ? matrix::staff($scope, $userids) : [];
        $clientmanagers = array_values(array_map(fn($m) => (int)$m->id, array_filter(
            manager::get_members($scope->id()),
            fn($m) => $m->role === manager::ROLE_MANAGER
        )));
        $bymanager = [];
        foreach ($rows as $row) {
            $mid = (int)($staff[(int)$row->userid]->managerid ?? 0);
            $to = $mid ? [$mid] : $clientmanagers;
            foreach ($to as $m) {
                $bymanager[(int)$m][] = $row;
            }
        }
        $course = rules::course_name((int)$rule->courseid);
        $url = new moodle_url('/local/clientportal/compliancedue.php', ['rule' => $rule->id, 'status' => 'overdue']);
        $told = 0;
        foreach ($bymanager as $managerid => $list) {
            $manager = \core_user::get_user($managerid);
            if (!$manager || $manager->deleted || $manager->suspended) {
                continue;
            }
            $lines = [];
            foreach ($list as $row) {
                $learner = \core_user::get_user((int)$row->userid);
                if ($learner) {
                    $lines[] = get_string('msg_manager_line', 'local_clientportal', (object)[
                        'name' => fullname($learner),
                        'due' => userdate(
                            (int)$row->timedue,
                            get_string('strftimedate', 'langconfig'),
                            \core_date::get_user_timezone($manager)
                        ),
                        'days' => max(1, (int)floor(($now - (int)$row->timedue) / DAYSECS) + 1),
                    ]);
                }
            }
            if (!$lines) {
                continue;
            }
            $a = (object)['client' => format_string($scope->client->name), 'name' => $manager->firstname,
                'course' => $course, 'count' => count($lines), 'url' => $url->out(false)];
            $one = count($lines) === 1 ? '1' : '';
            $subject = get_string('msg_manager_subject' . $one, 'local_clientportal', $a);
            $intro = get_string('msg_manager_body' . $one, 'local_clientportal', $a);
            $text = $intro . "\n\n- " . implode("\n- ", $lines) . "\n\n" . get_string('msg_manager_open', 'local_clientportal', $a);
            $html = \html_writer::tag('p', s($intro)) .
                \html_writer::alist(array_map('s', $lines)) .
                \html_writer::tag('p', \html_writer::link($url, get_string('msg_manager_button', 'local_clientportal')));
            if (self::send($scope, $manager, $subject, $text, $html, $url, 0)) {
                $told++;
            }
        }
        return $told;
    }

    /**
     * Send one message.
     *
     * @param scope $scope
     * @param stdClass $to
     * @param string $subject
     * @param string $text
     * @param string $html
     * @param moodle_url $url
     * @param int $courseid
     * @return bool
     */
    protected static function send(
        scope $scope,
        stdClass $to,
        string $subject,
        string $text,
        string $html,
        moodle_url $url,
        int $courseid
    ): bool {
        $from = clone \core_user::get_noreply_user();
        $from->firstname = format_string($scope->client->name);
        $from->lastname = '';
        $message = new \core\message\message();
        $message->component = 'local_clientportal';
        $message->name = 'compliance';
        $message->userfrom = $from;
        $message->userto = $to;
        $message->subject = $subject;
        $message->fullmessage = $text;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = $html;
        $message->smallmessage = $subject;
        $message->notification = 1;
        $message->contexturl = $url->out(false);
        $message->contexturlname = $subject;
        $message->courseid = $courseid ?: SITEID;
        try {
            return (bool)message_send($message);
        } catch (\Throwable $e) {
            debugging('Compliance message failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }
}
