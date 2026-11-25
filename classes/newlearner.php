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
 * Add learners from the portal, with or without any email.
 *
 * Without email, no new-account email and no course welcome message is sent. The password (set or generated)
 * is handed back once for the sign-in card and is never stored in plain text or logged.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class newlearner {
    /**
     * May this person add learners?
     *
     * @param scope $scope
     * @return bool
     */
    public static function can_add(scope $scope): bool {
        if ($scope->support || $scope->is_trainer()) {
            return false;
        }
        return $scope->is_manager() ||
            ($scope->role === manager::ROLE_REP && (bool)get_config('local_clientportal', 'repscanaddlearners'));
    }

    /**
     * Stop unless this person may add learners.
     *
     * @param scope $scope
     */
    public static function require_can_add(scope $scope): void {
        if (!self::can_add($scope)) {
            throw new moodle_exception('error_cannotaddlearners', 'local_clientportal');
        }
    }

    /**
     * Courses this person can put a new learner in: the client's own courses (managers, with the
     * "Build courses" feature on).
     *
     * @param scope $scope
     * @return array id => name
     */
    public static function course_options(scope $scope): array {
        if (!$scope->is_manager() || !courses::has($scope->client, courses::FEATURE_COURSES)) {
            return [];
        }
        $out = [];
        foreach (courses::list($scope) as $course) {
            $out[(int)$course->id] = format_string($course->fullname);
        }
        return $out;
    }

    /**
     * Add one learner.
     *
     * @param scope $scope
     * @param stdClass $data firstname, lastname, email, username (optional), password (optional; generated when
     *                       empty), forcechange (bool), sendemail (bool), groups (int[]), courses (int[])
     * @return stdClass ->user, ->existing (bool), ->password (string|null: only for a new account without email),
     *                  ->username, ->enrolled (int)
     */
    public static function add(scope $scope, stdClass $data): stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->dirroot . '/cohort/lib.php');
        self::require_can_add($scope);

        $email = trim((string)($data->email ?? ''));
        $firstname = trim((string)($data->firstname ?? ''));
        $lastname = trim((string)($data->lastname ?? ''));
        if (!validate_email($email)) {
            throw new moodle_exception('invalidemail');
        }
        $sendemail = !empty($data->sendemail);
        $result = (object)['existing' => false, 'password' => null, 'enrolled' => 0];

        if ($found = people::find_by_email($email)) {
            self::check_existing($scope, $found);
            $user = $found;
            $result->existing = true;
        } else {
            if ($firstname === '' || $lastname === '') {
                throw new moodle_exception('error_namerequired', 'local_clientportal');
            }
            $username = trim((string)($data->username ?? ''));
            $username = \core_text::strtolower($username === '' ? $email : $username);
            if ($username !== clean_param($username, PARAM_USERNAME) || $username === '') {
                throw new moodle_exception('error_badusername', 'local_clientportal');
            }
            if ($DB->record_exists('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id])) {
                throw new moodle_exception('error_usernametaken', 'local_clientportal', '', s($username));
            }
            $user = (object)[
                'auth' => 'manual',
                'confirmed' => 1,
                'mnethostid' => $CFG->mnet_localhost_id,
                'username' => $username,
                'firstname' => $firstname,
                'lastname' => $lastname,
                'email' => $email,
            ];
            $password = '';
            if (!$sendemail) {
                $password = (string)($data->password ?? '');
                if ($password === '') {
                    $password = generate_password();
                } else {
                    $errmsg = '';
                    if (!check_password_policy($password, $errmsg, $user)) {
                        throw new moodle_exception('error_passwordpolicy', 'local_clientportal', '', strip_tags($errmsg));
                    }
                }
            }
            $user->password = $password;
            // Moodle 5.3 moved user creation to \core\user::create_user(). With an empty password nothing is hashed.
            $user->id = method_exists('\\core\\user', 'create_user')
                ? \core\user::create_user($user, $password !== '', true)
                : user_create_user($user, $password !== '', true);
            $user = $DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST);
            if ($sendemail) {
                // Moodle's own new-account email with a generated password; they must change it.
                set_user_preference('auth_forcepasswordchange', 1, $user->id);
                setnew_password_and_mail($user);
            } else {
                if (!empty($data->forcechange)) {
                    set_user_preference('auth_forcepasswordchange', 1, $user->id);
                }
                $result->password = $password;
            }
        }

        if (!scope::is_learner($scope->id(), (int)$user->id)) {
            cohort_add_member((int)$scope->client->cohortid, (int)$user->id);
        }
        people::ensure_main((int)$user->id);

        $groupids = array_filter(array_map('intval', (array)($data->groups ?? [])));
        foreach ($groupids as $groupid) {
            manager::add_to_group($scope->id(), $groupid, [(int)$user->id]);
        }
        $courseids = array_intersect(
            array_map('intval', (array)($data->courses ?? [])),
            array_keys(self::course_options($scope))
        );
        foreach ($courseids as $courseid) {
            $result->enrolled += courses::enrol_learners($scope, $courseid, [(int)$user->id], $sendemail);
        }

        manager::log(
            $scope->id(),
            $result->existing ? 'learner_added_existing' : 'learner_added',
            (int)$user->id,
            get_string($sendemail ? 'log_emailsent' : 'log_noemail', 'local_clientportal')
        );
        $result->user = $user;
        $result->username = $user->username;
        return $result;
    }

    /**
     * Can an existing account be added as a learner of this client?
     *
     * @param scope $scope
     * @param stdClass $user
     */
    protected static function check_existing(scope $scope, stdClass $user): void {
        global $DB;
        if (is_siteadmin($user) || isguestuser($user)) {
            throw new moodle_exception('error_cannotaddthisaccount', 'local_clientportal');
        }
        if ($DB->record_exists('local_clientportal_member', ['clientid' => $scope->id(), 'userid' => $user->id])) {
            throw new moodle_exception('error_isstaff', 'local_clientportal');
        }
        if (!people::one_account()) {
            $others = array_diff(people::client_ids((int)$user->id), [$scope->id()]);
            if ($others) {
                throw new moodle_exception('error_emailinuse', 'local_clientportal');
            }
        }
    }

    /**
     * Read pasted CSV lines: firstname, lastname, email, then optionally username and password. A header line is
     * skipped.
     *
     * @param string $text
     * @return array[] rows of [line, firstname, lastname, email, username, password]
     */
    public static function parse_rows(string $text): array {
        $rows = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($text));
        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = array_map('trim', str_getcsv($line, strpos($line, "\t") !== false ? "\t" : ',', '"', ''));
            if ($i === 0 && \core_text::strtolower($cells[0] ?? '') === 'firstname') {
                continue;
            }
            $rows[] = [$i + 1, $cells[0] ?? '', $cells[1] ?? '', $cells[2] ?? '', $cells[3] ?? '', $cells[4] ?? ''];
        }
        return $rows;
    }
}
