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

/**
 * AI for client portal features, through Moodle's AI providers. Every answer is billed to the client it was for.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai {
    /**
     * Can the site generate text with AI?
     *
     * @return bool
     */
    public static function available(): bool {
        if (!class_exists('\\core_ai\\manager') || !class_exists('\\core_ai\\aiactions\\generate_text')) {
            return false;
        }
        try {
            return \core\di::get(\core_ai\manager::class)->is_action_available(\core_ai\aiactions\generate_text::class);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Has the person accepted the site's AI policy?
     *
     * @param int $userid
     * @return bool
     */
    public static function policy_accepted(int $userid): bool {
        return \core_ai\manager::get_user_policy_status($userid);
    }

    /**
     * Record that the person accepted the site's AI policy.
     *
     * @param int $userid
     */
    public static function accept_policy(int $userid): void {
        \core_ai\manager::user_policy_accepted($userid, \context_system::instance()->id);
    }

    /**
     * Ask the AI and return its text, billed to the client.
     *
     * @param scope $scope
     * @param string $prompt
     * @param string $description what the credits were for (shown on the client's usage)
     * @return string
     * @throws moodle_exception when AI is unavailable or fails
     */
    public static function generate(scope $scope, string $prompt, string $description): string {
        global $DB, $USER;
        if (!self::available()) {
            throw new moodle_exception('ai_unavailable', 'local_clientportal');
        }
        $contextid = \context_system::instance()->id;
        $before = (int)$DB->get_field_sql('SELECT MAX(id) FROM {ai_action_register}');
        $action = new \core_ai\aiactions\generate_text($contextid, (int)$USER->id, $prompt);
        $response = \core\di::get(\core_ai\manager::class)->process_action($action);
        if (!$response->get_success()) {
            throw new moodle_exception('ai_failed', 'local_clientportal', '', s($response->get_errormessage()));
        }
        // Bill this client now, so the usage collector does not guess the client later.
        $registered = $DB->get_records_select(
            'ai_action_register',
            'id > ? AND userid = ? AND success = 1',
            [$before, (int)$USER->id],
            'id DESC',
            'id, actionname',
            0,
            1
        );
        $register = reset($registered);
        usage::record(
            (int)$USER->id,
            $contextid,
            'local_clientportal',
            usage::ai_credits('generate_text'),
            $description,
            'aicredits',
            'core_ai',
            $register ? (int)$register->id : 0,
            $scope->id()
        );
        return (string)($response->get_response_data()['generatedcontent'] ?? '');
    }

    /**
     * Pull a JSON object out of an AI answer (which may be wrapped in a code fence).
     *
     * @param string $text
     * @return array|null
     */
    public static function json(string $text): ?array {
        $text = trim($text);
        $fence = str_repeat(chr(96), 3);
        if (preg_match('/' . $fence . '(?:json)?\s*(.+?)\s*' . $fence . '/s', $text, $m)) {
            $text = $m[1];
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $data = json_decode(substr($text, $start, $end - $start + 1), true);
        return is_array($data) ? $data : null;
    }
}
