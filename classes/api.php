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
 * Public API for other plugins.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api {
    /**
     * Record billable usage, for example AI credits a plugin spent for a user. The portal works out the client from
     * the context (a client's own course) or the user. Safe to call when the user belongs to no client: nothing is
     * recorded.
     *
     * Example: \local_clientportal\api::record_usage($USER->id, $context->id, 'mod_contentcreator', 5, 'Voiceover');
     *
     * @param int $userid
     * @param int $contextid
     * @param string $component
     * @param float $credits
     * @param string $description
     * @return int usage id, or 0
     */
    public static function record_usage(
        int $userid,
        int $contextid,
        string $component,
        float $credits,
        string $description = ''
    ): int {
        return usage::record($userid, $contextid, $component, $credits, $description);
    }

    /**
     * Record one call to the LMS Labs AI service. Pass the service's reply as it came back (JSON text, array or
     * object). The credits charged are read from the reply (creditsCharged, creditsUsed, creditsConsumed,
     * credits_used, credits_charged or charged, at the top level or under "data" or "usage"); when the reply
     * does not say, $fallback is used. A failed call (success or ok false, or an error) records nothing, because the
     * service does not charge for it. Safe to call for any user: outside a client nothing is recorded.
     * Pass $ref (for example a job id) when the same charge can be seen more than once, such as a job that is
     * polled until it finishes: it is then recorded only once.
     *
     * Example, right after the plugin decodes the reply:
     * if (class_exists('\local_clientportal\api')) {
     *     \local_clientportal\api::record_lmslabs_call($USER->id, $context->id, 'mod_aiquiz', $result, 10, 'Quiz');
     * }
     *
     * @param int $userid
     * @param int $contextid
     * @param string $component
     * @param mixed $reply
     * @param float $fallback credits to record when the reply does not say
     * @param string $description
     * @param string $ref optional id of the charge, so it is recorded once
     * @return int usage id, or 0
     */
    public static function record_lmslabs_call(
        int $userid,
        int $contextid,
        string $component,
        $reply,
        float $fallback = 0,
        string $description = '',
        string $ref = ''
    ): int {
        global $DB, $COURSE;
        try {
            $credits = self::charged_credits($reply, $fallback);
            // A plugin that cannot see the course passes the system context: use the page's course then.
            if ($contextid <= 0 || $contextid == \context_system::instance()->id) {
                $coursectx = !empty($COURSE->id) && (int)$COURSE->id !== (int)SITEID
                    ? \context_course::instance($COURSE->id, IGNORE_MISSING) : false;
                if ($coursectx) {
                    $contextid = (int)$coursectx->id;
                }
            }
            if ($credits === null || $credits <= 0) {
                return 0;
            }
            $source = 'api';
            if ($ref !== '') {
                $source = 'ref:' . substr(sha1($component . '|' . $ref), 0, 26);
                if ($DB->record_exists('local_clientportal_usage', ['source' => $source, 'sourceid' => 0])) {
                    return 0;
                }
            }
            return usage::record($userid, $contextid, $component, $credits, $description, 'aicredits', $source);
        } catch (\Throwable $e) {
            // Billing must never break the plugin that called us.
            debugging('Client portal could not record usage: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return 0;
        }
    }

    /**
     * Credits a reply from the LMS Labs service says were charged.
     *
     * @param mixed $reply JSON text, array or object
     * @param float $fallback used when the reply does not say
     * @return float|null null when the call failed
     */
    public static function charged_credits($reply, float $fallback = 0): ?float {
        if (is_string($reply)) {
            $decoded = json_decode($reply, true);
            $reply = is_array($decoded) ? $decoded : [];
        }
        if (is_object($reply)) {
            $reply = json_decode(json_encode($reply), true) ?: [];
        }
        if (!is_array($reply)) {
            $reply = [];
        }
        if (array_key_exists('success', $reply) && empty($reply['success'])) {
            return null;
        }
        if (array_key_exists('ok', $reply) && empty($reply['ok'])) {
            return null;
        }
        if (!empty($reply['error'])) {
            return null;
        }
        $keys = ['creditsCharged', 'creditsUsed', 'creditsConsumed', 'credits_used', 'credits_charged', 'charged'];
        foreach ([$reply, $reply['data'] ?? null, $reply['usage'] ?? null] as $level) {
            if (!is_array($level)) {
                continue;
            }
            foreach ($keys as $key) {
                if (isset($level[$key]) && is_numeric($level[$key])) {
                    return (float)$level[$key];
                }
            }
        }
        return $fallback;
    }

    /**
     * Clients a user is a learner of (cohort members who are not staff).
     *
     * @param int $userid
     * @return int[]
     */
    public static function learner_clients(int $userid): array {
        global $DB;
        $sql = "SELECT c.id
                  FROM {local_clientportal_client} c
                  JOIN {cohort_members} cm ON cm.cohortid = c.cohortid AND cm.userid = :u
                 WHERE NOT EXISTS (SELECT 1 FROM {local_clientportal_member} m WHERE m.clientid = c.id AND m.userid = :u2)";
        return array_map('intval', $DB->get_fieldset_sql($sql, ['u' => $userid, 'u2' => $userid]));
    }

    /**
     * Is the user portal staff of the client?
     *
     * @param int $clientid
     * @param int $userid
     * @return bool
     */
    public static function is_staff(int $clientid, int $userid): bool {
        global $DB;
        return $DB->record_exists('local_clientportal_member', ['clientid' => $clientid, 'userid' => $userid]);
    }

    /**
     * The client that owns a course through its category, or 0.
     *
     * @param int $courseid
     * @return int
     */
    public static function course_client(int $courseid): int {
        return courses::owner($courseid);
    }
}
