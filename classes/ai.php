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
 * AI for client portal features, through the LMS Labs service and its AI credits, like every LMS Labs plugin.
 * Every call is billed to the client it was for.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai {
    /** Route and fallback credits per task. */
    public const TASKS = [
        'structure' => ['path' => '/api/client-portal/structure', 'credits' => 20],
        'report_question' => ['path' => '/api/client-portal/report-question', 'credits' => 3],
    ];

    /**
     * The site's LMS Labs credentials (from AI Grader Central Config), or null when not set up.
     *
     * @return array|null [siteid, apikey]
     */
    public static function credentials(): ?array {
        global $CFG;
        $lib = $CFG->dirroot . '/local/aiconfig/lib.php';
        if (file_exists($lib)) {
            require_once($lib);
        }
        $siteid = function_exists('local_aiconfig_get_siteid') ? trim((string)(local_aiconfig_get_siteid() ?? '')) : '';
        $apikey = function_exists('local_aiconfig_get_apikey') ? trim((string)(local_aiconfig_get_apikey() ?? '')) : '';
        if ($siteid === '' || $apikey === '') {
            $siteid = trim((string)get_config('local_clientportal', 'lmslabssiteid'));
            $apikey = trim((string)get_config('local_clientportal', 'lmslabsapikey'));
        }
        return $siteid !== '' && $apikey !== '' ? [$siteid, $apikey] : null;
    }

    /**
     * Is LMS Labs AI set up for this site?
     *
     * @return bool
     */
    public static function available(): bool {
        return self::credentials() !== null;
    }

    /**
     * The LMS Labs service address.
     *
     * @return string
     */
    private static function base(): string {
        return rtrim((string)(get_config('local_clientportal', 'lmslabsurl') ?: 'https://lms-labs.com'), '/');
    }

    /**
     * Run an AI task on the LMS Labs service and bill the AI credits to the client.
     *
     * @param scope $scope
     * @param string $task a key of TASKS
     * @param string $prompt
     * @param string $description shown on the client's usage
     * @return array the service's reply (ok true)
     * @throws moodle_exception when AI is not set up, credits are short, or the call fails
     */
    public static function run(scope $scope, string $task, string $prompt, string $description): array {
        global $CFG, $USER;
        require_once($CFG->libdir . '/filelib.php');
        $credentials = self::credentials();
        if (!$credentials || !isset(self::TASKS[$task])) {
            throw new moodle_exception('ai_unavailable', 'local_clientportal');
        }
        [$siteid, $apikey] = $credentials;
        // The same request sent twice (a double click, a retry) is charged once.
        $requestkey = substr(hash('sha256', $task . '|' . $scope->id() . '|' . $USER->id . '|' . $prompt), 0, 64);
        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 120, 'CURLOPT_SSL_VERIFYPEER' => true]);
        $curl->setHeader(['Content-Type: application/json', 'X-Api-Key: ' . $apikey, 'Idempotency-Key: ' . $requestkey]);
        $response = $curl->post(self::base() . self::TASKS[$task]['path'], json_encode([
            'siteId' => $siteid,
            'apiKey' => $apikey,
            'prompt' => $prompt,
            'plugin' => 'local_clientportal',
            'action' => $task,
        ]));
        if ($curl->error) {
            throw new moodle_exception('ai_failed', 'local_clientportal', '', s($curl->error));
        }
        $status = (int)($curl->info['http_code'] ?? 0);
        $data = json_decode((string)$response, true);
        if (!is_array($data) || empty($data['ok']) || $status < 200 || $status >= 300) {
            $error = is_array($data) ? (string)($data['error'] ?? '') : '';
            if ($status === 402 || $error === 'INSUFFICIENT_CREDITS') {
                throw new moodle_exception('ai_nocredits', 'local_clientportal');
            }
            throw new moodle_exception('ai_failed', 'local_clientportal', '', s($error !== '' ? $error : 'HTTP ' . $status));
        }
        api::record_lmslabs_call(
            (int)$USER->id,
            \context_system::instance()->id,
            'local_clientportal',
            $data,
            self::TASKS[$task]['credits'],
            $description,
            $requestkey,
            $scope->id()
        );
        return $data;
    }
}
