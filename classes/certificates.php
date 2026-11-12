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
 * Certificates for the portal: Custom certificate (mod_customcert) and Moodle Workplace / Certificate
 * manager (tool_certificate). Only courses the client owns are considered.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class certificates {
    /** Custom certificate activity. */
    public const CUSTOMCERT = 'customcert';
    /** Certificate manager issue. */
    public const TOOLCERT = 'toolcert';

    /**
     * Is mod_customcert installed?
     *
     * @return bool
     */
    private static function has_customcert(): bool {
        return \core_component::get_component_directory('mod_customcert') !== null &&
            class_exists('\\mod_customcert\\template') && self::table_exists('customcert_issues');
    }

    /**
     * Is tool_certificate installed?
     *
     * @return bool
     */
    private static function has_toolcert(): bool {
        return \core_component::get_component_directory('tool_certificate') !== null &&
            class_exists('\\tool_certificate\\template') && self::table_exists('tool_certificate_issues');
    }

    /**
     * Does a table exist?
     *
     * @param string $table
     * @return bool
     */
    private static function table_exists(string $table): bool {
        global $DB;
        static $cache = [];
        return $cache[$table] ??= $DB->get_manager()->table_exists($table);
    }

    /**
     * Certificates for a learner in the given (owned) courses.
     *
     * Each item: type, id, courseid, name, issued (time or null), code, state (issued|ready|locked).
     * "ready" means the learner can see the certificate activity but has not opened it yet; downloading
     * it from the portal issues it, exactly as the learner opening it would.
     *
     * @param int $userid
     * @param int[] $courseids
     * @return array courseid => item[]
     */
    public static function for_learner(int $userid, array $courseids): array {
        global $DB;
        $out = [];
        if (!$courseids) {
            return $out;
        }
        if (self::has_customcert()) {
            [$in, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
            $params['userid'] = $userid;
            $params['modname'] = 'customcert';
            $sql = "SELECT cm.id AS cmid, c.id, c.course, c.name, ci.id AS issueid, ci.code, ci.timecreated AS issued
                      FROM {customcert} c
                      JOIN {modules} md ON md.name = :modname
                      JOIN {course_modules} cm ON cm.instance = c.id AND cm.module = md.id
                           AND cm.visible = 1 AND cm.deletioninprogress = 0
                 LEFT JOIN {customcert_issues} ci ON ci.customcertid = c.id AND ci.userid = :userid
                     WHERE c.course $in
                  ORDER BY c.name";
            foreach ($DB->get_records_sql($sql, $params) as $r) {
                if ($r->issueid) {
                    $state = 'issued';
                } else {
                    $cm = get_fast_modinfo((int)$r->course, $userid)->get_cm((int)$r->cmid);
                    $state = $cm->uservisible ? 'ready' : 'locked';
                }
                $out[$r->course][] = (object)[
                    'type' => self::CUSTOMCERT,
                    'id' => (int)$r->cmid,
                    'courseid' => (int)$r->course,
                    'name' => format_string($r->name, true, ['context' => \context_module::instance($r->cmid)]),
                    'issued' => $r->issued ? (int)$r->issued : null,
                    'code' => $r->code,
                    'state' => $state,
                ];
            }
        }
        if (self::has_toolcert()) {
            [$in, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
            $params['userid'] = $userid;
            $sql = "SELECT i.id, i.courseid, i.code, i.timecreated, i.expires, t.name
                      FROM {tool_certificate_issues} i
                      JOIN {tool_certificate_templates} t ON t.id = i.templateid
                     WHERE i.userid = :userid AND i.archived = 0 AND i.courseid $in
                  ORDER BY i.timecreated DESC";
            foreach ($DB->get_records_sql($sql, $params) as $r) {
                $out[$r->courseid][] = (object)[
                    'type' => self::TOOLCERT,
                    'id' => (int)$r->id,
                    'courseid' => (int)$r->courseid,
                    'name' => format_string($r->name),
                    'issued' => (int)$r->timecreated,
                    'expires' => $r->expires ? (int)$r->expires : null,
                    'code' => $r->code,
                    'state' => 'issued',
                ];
            }
        }
        return $out;
    }

    /**
     * Build a certificate PDF for a learner, checking it belongs to the client.
     *
     * @param scope $scope
     * @param int $userid
     * @param string $type customcert|toolcert
     * @param int $id course module id (customcert) or issue id (toolcert)
     * @return array [filename, pdf content]
     */
    public static function pdf(scope $scope, int $userid, string $type, int $id): array {
        global $DB;
        $user = $scope->require_learner($userid);
        if ($type === self::CUSTOMCERT && self::has_customcert()) {
            $cm = get_coursemodule_from_id('customcert', $id, 0, false, MUST_EXIST);
            $scope->require_owned($userid, (int)$cm->course);
            $customcert = $DB->get_record('customcert', ['id' => $cm->instance], '*', MUST_EXIST);
            $issue = $DB->get_record('customcert_issues', ['customcertid' => $customcert->id, 'userid' => $userid]);
            if (!$issue) {
                $cminfo = get_fast_modinfo((int)$cm->course, $userid)->get_cm((int)$cm->id);
                if (!$cminfo->uservisible) {
                    throw new moodle_exception('error_certlocked', 'local_clientportal');
                }
                self::customcert_issue((int)$customcert->id, $userid);
                manager::log($scope->id(), 'certificate_issued', $userid, $customcert->name);
            }
            $record = $DB->get_record('customcert_templates', ['id' => $customcert->templateid], '*', MUST_EXIST);
            $content = self::customcert_pdf($record, $userid);
            $name = clean_filename(fullname($user) . ' - ' . format_string($customcert->name) . '.pdf');
            return [$name, $content];
        }
        if ($type === self::TOOLCERT && self::has_toolcert()) {
            $issue = $DB->get_record('tool_certificate_issues', ['id' => $id, 'userid' => $userid], '*', MUST_EXIST);
            $scope->require_owned($userid, (int)$issue->courseid);
            $file = \tool_certificate\template::instance((int)$issue->templateid)->get_issue_file($issue);
            $template = $DB->get_field('tool_certificate_templates', 'name', ['id' => $issue->templateid]);
            $name = clean_filename(fullname($user) . ' - ' . format_string($template) . '.pdf');
            return [$name, $file->get_content()];
        }
        throw new moodle_exception('error_nocert', 'local_clientportal');
    }

    /**
     * Issue a Custom certificate, with the service API (customcert 5.2+) or the older static API.
     *
     * @param int $customcertid
     * @param int $userid
     */
    private static function customcert_issue(int $customcertid, int $userid): void {
        if (class_exists('\\mod_customcert\\service\\certificate_issue_service')) {
            \mod_customcert\service\certificate_issue_service::create()->issue_certificate($customcertid, $userid);
        } else {
            \mod_customcert\certificate::issue_certificate($customcertid, $userid);
        }
    }

    /**
     * A Custom certificate PDF as a string, with the service API (customcert 5.2+) or the older template API.
     *
     * @param stdClass $record customcert_templates record
     * @param int $userid
     * @return string
     */
    private static function customcert_pdf(stdClass $record, int $userid): string {
        if (
            class_exists('\\mod_customcert\\service\\pdf_generation_service') &&
                method_exists('\\mod_customcert\\template', 'from_record')
        ) {
            $template = \mod_customcert\template::from_record($record);
            $service = \mod_customcert\service\pdf_generation_service::create();
            return (string)$service->generate_pdf($template, false, $userid, true);
        }
        $template = new \mod_customcert\template($record);
        return (string)$template->generate_pdf(false, $userid, true);
    }

    /**
     * All issued certificates for the client's learners (optionally one group), for a bulk download.
     *
     * @param scope $scope
     * @param int|null $groupid
     * @return stdClass[] items with ->userid
     */
    public static function issued_for_client(scope $scope, ?int $groupid = null): array {
        $report = new report($scope);
        $bylearner = [];
        foreach ($report->enrolments(null, $groupid) as $row) {
            $bylearner[$row->userid][] = (int)$row->courseid;
        }
        $items = [];
        foreach ($bylearner as $userid => $courseids) {
            foreach (self::for_learner((int)$userid, $courseids) as $certs) {
                foreach ($certs as $cert) {
                    if ($cert->state === 'issued') {
                        $cert->userid = (int)$userid;
                        $items[] = $cert;
                    }
                }
            }
        }
        return $items;
    }
}
