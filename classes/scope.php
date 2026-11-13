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

use context_system;
use moodle_exception;
use moodle_url;
use stdClass;

/**
 * Who is using the portal, for which client, and which learners and enrolments they may see.
 *
 * Visibility rules:
 *  - A client's learners are the members of its cohort, minus its staff.
 *  - For each learner, the client only sees courses it owns (local_clientportal_enrol). A learner
 *    who also studies through another client, or privately, keeps those courses private.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scope {
    /** @var stdClass client record */
    public stdClass $client;
    /** @var string manager|rep */
    public string $role;
    /** @var bool viewing as site staff (support view) */
    public bool $support = false;

    /**
     * Constructor.
     *
     * @param stdClass $client
     * @param string $role
     * @param bool $support
     */
    public function __construct(stdClass $client, string $role, bool $support = false) {
        $this->client = $client;
        $this->role = $role;
        $this->support = $support;
    }

    /**
     * Is this a client manager (or site staff)?
     *
     * @return bool
     */
    public function is_manager(): bool {
        return $this->role === manager::ROLE_MANAGER;
    }

    /**
     * Is this a client trainer (teaches the client's own courses only)?
     *
     * @return bool
     */
    public function is_trainer(): bool {
        return $this->role === manager::ROLE_TRAINER;
    }

    /**
     * Does this person mark work across the client's courses?
     *
     * @return bool
     */
    public function is_assessor(): bool {
        global $DB, $USER;
        return !$this->support && courses::has($this->client, courses::FEATURE_ASSESSORS) &&
            (bool)$DB->get_field('local_clientportal_member', 'assessor', ['clientid' => $this->id(), 'userid' => $USER->id]);
    }

    /**
     * Client id.
     *
     * @return int
     */
    public function id(): int {
        return (int)$this->client->id;
    }

    /**
     * Clients a user is staff of.
     *
     * @param int $userid
     * @return stdClass[] client records with ->role
     */
    public static function memberships(int $userid): array {
        global $DB;
        $sql = "SELECT c.*, m.role
                  FROM {local_clientportal_member} m
                  JOIN {local_clientportal_client} c ON c.id = m.clientid
                 WHERE m.userid = :userid
              ORDER BY c.name";
        return $DB->get_records_sql($sql, ['userid' => $userid]);
    }

    /**
     * Start a portal page: log in, work out the client, and set up the page.
     *
     * Client staff get their own client (a ?client= switch is honoured only for clients they belong to).
     * Site staff with local/clientportal:manageclients can open any client with ?client=, for support.
     *
     * @param moodle_url $url
     * @param bool $managersonly
     * @return self
     */
    public static function require_portal(moodle_url $url, bool $managersonly = false): self {
        global $PAGE, $USER, $SESSION;
        require_login(null, false);
        $syscontext = context_system::instance();
        $requested = optional_param('client', 0, PARAM_INT);
        $memberships = self::memberships((int)$USER->id);
        $scope = null;

        if ($memberships) {
            $chosen = null;
            if ($requested && isset($memberships[$requested])) {
                $chosen = $memberships[$requested];
            } else if (!empty($SESSION->local_clientportal_client) && isset($memberships[$SESSION->local_clientportal_client])) {
                $chosen = $memberships[$SESSION->local_clientportal_client];
            } else {
                $chosen = reset($memberships);
            }
            $SESSION->local_clientportal_client = (int)$chosen->id;
            $role = $chosen->role;
            unset($chosen->role);
            $scope = new self($chosen, $role);
        } else if (has_capability('local/clientportal:manageclients', $syscontext)) {
            $clientid = $requested ?: (int)($SESSION->local_clientportal_support ?? 0);
            $client = $clientid ? manager::get_client($clientid) : false;
            if (!$client) {
                redirect(new moodle_url('/local/clientportal/admin/clients.php'));
            }
            $SESSION->local_clientportal_support = (int)$client->id;
            $scope = new self($client, manager::ROLE_MANAGER, true);
        } else {
            throw new moodle_exception('error_noportal', 'local_clientportal');
        }

        $PAGE->set_context($syscontext);
        $PAGE->set_url($url);
        $PAGE->set_pagelayout('secure');
        $PAGE->add_body_class('local-clientportal');
        $PAGE->set_title(format_string($scope->client->name) . ': ' . get_string('pluginname', 'local_clientportal'));
        $PAGE->set_heading(format_string($scope->client->name));

        if (empty($scope->client->active) && !$scope->support) {
            echo ui::header($scope, '');
            echo \html_writer::div(get_string('clientinactive', 'local_clientportal'), 'alert alert-warning');
            echo ui::footer();
            exit;
        }
        // Trainers only see their courses, marking and help.
        if ($scope->is_trainer()) {
            $page = basename($url->get_path());
            if (!in_array($page, ['courses.php', 'coursemanage.php', 'marking.php', 'help.php'], true)) {
                redirect(new moodle_url('/local/clientportal/courses.php'));
            }
        }
        if ($managersonly && !$scope->is_manager()) {
            throw new moodle_exception('error_managersonly', 'local_clientportal');
        }
        return $scope;
    }

    /**
     * SQL that restricts a user alias to this client's learners.
     *
     * @param string $alias user table alias
     * @param string $prefix unique parameter prefix
     * @return array [join, where, params]
     */
    public function learner_sql(string $alias = 'u', string $prefix = 'ls'): array {
        $join = "JOIN {cohort_members} {$prefix}cm ON {$prefix}cm.userid = {$alias}.id AND {$prefix}cm.cohortid = :{$prefix}cohort";
        $where = "{$alias}.deleted = 0 AND NOT EXISTS (SELECT 1 FROM {local_clientportal_member} {$prefix}m
                                                        WHERE {$prefix}m.clientid = :{$prefix}client
                                                          AND {$prefix}m.userid = {$alias}.id)";
        return [$join, $where, ["{$prefix}cohort" => (int)$this->client->cohortid, "{$prefix}client" => $this->id()]];
    }

    /**
     * Is the user a learner of the client?
     *
     * @param int $clientid
     * @param int $userid
     * @return bool
     */
    public static function is_learner(int $clientid, int $userid): bool {
        global $DB;
        $client = manager::get_client($clientid);
        if (!$client) {
            return false;
        }
        $sql = "SELECT 1
                  FROM {user} u
                  JOIN {cohort_members} cm ON cm.userid = u.id AND cm.cohortid = :cohortid
                 WHERE u.id = :userid AND u.deleted = 0
                   AND NOT EXISTS (SELECT 1 FROM {local_clientportal_member} m WHERE m.clientid = :clientid AND m.userid = u.id)";
        return $DB->record_exists_sql($sql, ['cohortid' => $client->cohortid, 'userid' => $userid, 'clientid' => $clientid]);
    }

    /**
     * Get a learner of this client, or stop.
     *
     * @param int $userid
     * @return stdClass user
     */
    public function require_learner(int $userid): stdClass {
        if (!self::is_learner($this->id(), $userid)) {
            throw new moodle_exception('error_notlearner', 'local_clientportal');
        }
        return \core_user::get_user($userid, '*', MUST_EXIST);
    }

    /**
     * Does this client own the learner's enrolment in the course?
     *
     * @param int $userid
     * @param int $courseid
     * @return bool
     */
    public function owns(int $userid, int $courseid): bool {
        global $DB;
        return $DB->record_exists(
            'local_clientportal_enrol',
            ['clientid' => $this->id(), 'userid' => $userid, 'courseid' => $courseid]
        );
    }

    /**
     * Stop unless this client owns the learner's enrolment in the course.
     *
     * @param int $userid
     * @param int $courseid
     */
    public function require_owned(int $userid, int $courseid): void {
        $this->require_learner($userid);
        if (!$this->owns($userid, $courseid)) {
            throw new moodle_exception('error_notowned', 'local_clientportal');
        }
    }

    /**
     * Portal URL carrying the client for site staff in support view.
     *
     * @param string $path e.g. /local/clientportal/learners.php
     * @param array $params
     * @return moodle_url
     */
    public function url(string $path, array $params = []): moodle_url {
        if ($this->support) {
            $params['client'] = $this->id();
        }
        return new moodle_url($path, $params);
    }
}
