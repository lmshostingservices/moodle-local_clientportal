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

use local_clientportal\integration\matrix;
use moodle_exception;

/**
 * AI-suggested organisation structure: departments, the positions in each, and the competencies each position
 * needs. The manager reviews and edits the suggestion, then it is created in Training Matrix for the client.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class structure {
    /** Training Matrix competency categories. */
    public const CATEGORIES = ['Safety', 'Technical', 'Leadership', 'Compliance', 'Professional Development', 'Induction'];
    /** Limits that keep a suggestion reviewable. */
    public const MAX_DEPARTMENTS = 8;
    /** @var int */
    public const MAX_POSITIONS = 6;
    /** @var int */
    public const MAX_COMPETENCIES = 8;

    /**
     * Ask the AI for a structure.
     *
     * @param scope $scope
     * @param string $business what the organisation does, in the manager's words
     * @param string $people how many people, roughly (optional)
     * @return array normalised suggestion (see normalise())
     */
    public static function suggest(scope $scope, string $business, string $people = ''): array {
        $existing = self::existing($scope);
        $prompt = "You design training structures for Australian workplaces (WHS, VET and industry practice).\n" .
            "Organisation: " . format_string($scope->client->name, true, ['escape' => false]) . "\n" .
            "What they do: " . trim($business) . "\n" .
            ($people !== '' ? "Number of people: " . trim($people) . "\n" : '') .
            "Suggest a complete structure: departments, the positions in each department, and the competencies each " .
            "position needs to be safe and competent. Up to " . self::MAX_DEPARTMENTS . " departments, up to " .
            self::MAX_POSITIONS . " positions per department and up to " . self::MAX_COMPETENCIES . " competencies per " .
            "position. Reuse a competency (same name and code) when several positions need it. Use these existing names " .
            "exactly when they fit, instead of inventing near-duplicates:\n" .
            "Existing departments: " . (implode('; ', array_unique(array_values($existing['departments']))) ?: 'none') . "\n" .
            "Existing positions: " . (implode('; ', array_unique(array_values($existing['positions']))) ?: 'none') . "\n" .
            "Existing competencies: " . (implode('; ', array_unique(array_values($existing['competencies']))) ?: 'none') . "\n" .
            "Competency categories must be one of: " . implode(', ', self::CATEGORIES) . ".\n" .
            "Mark a competency mandatory when the law, a licence or site safety requires it.\n" .
            "Reply with JSON only, in this shape:\n" .
            '{"departments":[{"name":"","description":"","positions":[{"name":"","description":"","competencies":' .
            '[{"name":"","code":"","category":"Safety","description":"","mandatory":true}]}]}]}';
        $answer = ai::generate($scope, $prompt, get_string('structure_usage', 'local_clientportal'));
        $data = ai::json($answer);
        $suggestion = $data ? self::normalise($data, $existing) : [];
        if (!$suggestion) {
            throw new moodle_exception('structure_badanswer', 'local_clientportal');
        }
        return $suggestion;
    }

    /**
     * Names the client already has (its own and shared), lower-cased name => id per kind.
     *
     * @param scope $scope
     * @return array ['departments' => [id => name], 'positions' => [...], 'competencies' => [...]]
     */
    public static function existing(scope $scope): array {
        global $DB;
        $positions = array_map(fn($p) => format_string($p->name), matrix::positions($scope));
        $competencies = $DB->get_records_sql_menu(
            matrix::sql('SELECT id, name FROM {local_tm_competencies} WHERE clientid = 0 OR clientid = :client ORDER BY name'),
            ['client' => $scope->id()]
        );
        return [
            'departments' => matrix::departments($scope),
            'positions' => $positions,
            'competencies' => array_map('format_string', $competencies),
        ];
    }

    /**
     * Clean an AI answer into a safe, limited structure, and note what already exists.
     *
     * @param array $data
     * @param array $existing from existing()
     * @return array list of departments: name, description, existingid, positions => list of name, description,
     *               existingid, competencies => list of name, code, category, description, mandatory, existingid
     */
    public static function normalise(array $data, array $existing): array {
        $find = fn(string $name, array $names): int => self::match($name, $names);
        $text = fn($v, int $max) => \core_text::substr(trim(clean_param(strip_tags((string)$v), PARAM_TEXT)), 0, $max);
        $out = [];
        foreach (array_slice((array)($data['departments'] ?? []), 0, self::MAX_DEPARTMENTS) as $d) {
            $dname = $text($d['name'] ?? '', 255);
            if ($dname === '') {
                continue;
            }
            $positions = [];
            foreach (array_slice((array)($d['positions'] ?? []), 0, self::MAX_POSITIONS) as $p) {
                $pname = $text($p['name'] ?? '', 255);
                if ($pname === '') {
                    continue;
                }
                $comps = [];
                $seen = [];
                foreach (array_slice((array)($p['competencies'] ?? []), 0, self::MAX_COMPETENCIES) as $c) {
                    $cname = $text($c['name'] ?? '', 255);
                    if ($cname === '' || isset($seen[\core_text::strtolower($cname)])) {
                        continue;
                    }
                    $seen[\core_text::strtolower($cname)] = true;
                    $category = in_array($c['category'] ?? '', self::CATEGORIES, true) ? $c['category'] : 'Technical';
                    $comps[] = [
                        'name' => $cname,
                        'code' => $text($c['code'] ?? '', 50),
                        'category' => $category,
                        'description' => $text($c['description'] ?? '', 1000),
                        'mandatory' => !empty($c['mandatory']),
                        'existingid' => $find($cname, $existing['competencies']),
                    ];
                }
                $positions[] = [
                    'name' => $pname,
                    'description' => $text($p['description'] ?? '', 1000),
                    'existingid' => $find($pname, $existing['positions']),
                    'competencies' => $comps,
                ];
            }
            $out[] = [
                'name' => $dname,
                'description' => $text($d['description'] ?? '', 1000),
                'existingid' => $find($dname, $existing['departments']),
                'positions' => $positions,
            ];
        }
        return $out;
    }

    /**
     * Create the chosen parts of a structure for the client. Existing items are reused, never changed.
     *
     * @param scope $scope
     * @param array $chosen same shape as normalise(), only the parts to create
     * @return array counts: departments, positions, competencies, links
     */
    public static function create(scope $scope, array $chosen): array {
        global $DB;
        $now = time();
        $counts = ['departments' => 0, 'positions' => 0, 'competencies' => 0, 'links' => 0];
        $existing = self::existing($scope);
        $compids = [];
        $transaction = $DB->start_delegated_transaction();
        foreach ($chosen as $d) {
            $deptid = self::match($d['name'], $existing['departments']);
            if (!$deptid) {
                $deptid = (int)$DB->insert_record(matrix::t('local_tm_departments'), (object)[
                    'name' => $d['name'], 'description' => $d['description'], 'sortorder' => 0,
                    'clientid' => $scope->id(), 'timecreated' => $now, 'timemodified' => $now,
                ]);
                $existing['departments'][$deptid] = $d['name'];
                $counts['departments']++;
            }
            foreach ($d['positions'] as $p) {
                $posid = self::match($p['name'], $existing['positions']);
                if (!$posid) {
                    $posid = (int)$DB->insert_record(matrix::t('local_tm_positions'), (object)[
                        'name' => $p['name'], 'description' => $p['description'], 'departmentid' => $deptid, 'level' => 1,
                        'clientid' => $scope->id(), 'timecreated' => $now, 'timemodified' => $now,
                    ]);
                    $existing['positions'][$posid] = $p['name'];
                    $counts['positions']++;
                }
                foreach ($p['competencies'] as $c) {
                    $key = \core_text::strtolower($c['name']);
                    $compid = $compids[$key] ?? self::match($c['name'], $existing['competencies']);
                    if (!$compid) {
                        $compid = (int)$DB->insert_record(matrix::t('local_tm_competencies'), (object)[
                            'name' => $c['name'], 'code' => $c['code'], 'description' => $c['description'],
                            'category' => $c['category'], 'mandatory' => $c['mandatory'] ? 1 : 0,
                            'clientid' => $scope->id(), 'timecreated' => $now, 'timemodified' => $now,
                        ]);
                        $existing['competencies'][$compid] = $c['name'];
                        $counts['competencies']++;
                    }
                    $compids[$key] = $compid;
                    $link = ['positionid' => $posid, 'competencyid' => $compid];
                    if (!$DB->record_exists(matrix::t('local_tm_pos_comps'), $link)) {
                        $DB->insert_record(matrix::t('local_tm_pos_comps'), (object)($link + [
                            'mandatory' => $c['mandatory'] ? 1 : 0, 'timecreated' => $now,
                        ]));
                        $counts['links']++;
                    }
                }
            }
        }
        $transaction->allow_commit();
        manager::log($scope->id(), 'structure_created', null, json_encode($counts));
        return $counts;
    }

    /**
     * Find a name among existing ones.
     *
     * @param string $name
     * @param array $names id => name
     * @return int id or 0
     */
    private static function match(string $name, array $names): int {
        foreach ($names as $id => $existing) {
            if (\core_text::strtolower(trim($existing)) === \core_text::strtolower(trim($name))) {
                return (int)$id;
            }
        }
        return 0;
    }
}
