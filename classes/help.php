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
 * Help guides for client staff, in the same order as the tutorial video.
 *
 * Each guide has a screenshot in pix/help and pins as [left, top, width, height] in percent of the image.
 * Titles, steps and pin labels are language strings (help_<id>, help_<id>_desc, help_<id>_pin<n>).
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class help {
    /** @var string[] Chapter keys in order. */
    const CHAPTERS = [
        'start', 'dashboard', 'learners', 'groups', 'reports', 'certificates', 'onboarding', 'compliance', 'pathways',
        'coursebuilder', 'marking', 'team', 'privacy',
    ];

    /** @var array Guides keyed by id, in order. */
    const GUIDES = [
        'portal' => ['chapter' => 'start', 'shot' => 'm_dash', 'managers' => false, 'needs' => '',
            'pins' => [[2.3, 1.8, 24.2, 4.1], [2.2, 7.1, 42.8, 4.2]]],
        'dashboard' => ['chapter' => 'dashboard', 'shot' => 'm_dash', 'managers' => false, 'needs' => '',
            'pins' => [[2.3, 18.6, 15.0, 10.6], [34.5, 18.6, 15.0, 10.6], [66.6, 18.6, 15.0, 10.6]]],
        'dashcourses' => ['chapter' => 'dashboard', 'shot' => 'm_dash', 'managers' => false, 'needs' => '',
            'pins' => [[3.8, 32.7, 4.8, 1.8], [3.8, 63.1, 11.8, 1.8], [52.2, 63.1, 4.2, 1.8]]],
        'dashgroup' => ['chapter' => 'dashboard', 'shot' => 'm_dash_group', 'managers' => false, 'needs' => '',
            'pins' => [[85.4, 13.5, 12.3, 3.4]]],
        'learners' => ['chapter' => 'learners', 'shot' => 'm_learners', 'managers' => false, 'needs' => '',
            'pins' => [[24.7, 24.5, 9.2, 3.7], [81.1, 25.6, 7.9, 1.6]]],
        'bulk' => ['chapter' => 'learners', 'shot' => 'm_bulk', 'managers' => false, 'needs' => '',
            'pins' => [[59.0, 86.2, 8.8, 3.4]]],
        'learner' => ['chapter' => 'learners', 'shot' => 'm_learner', 'managers' => false, 'needs' => '',
            'pins' => [[19.0, 32.1, 8.8, 3.7], [27.8, 32.1, 12.2, 3.7], [63.2, 32.1, 5.6, 3.7]]],
        'activities' => ['chapter' => 'learners', 'shot' => 'm_activities', 'managers' => false, 'needs' => '',
            'pins' => [[3.8, 59.4, 5.5, 1.8], [57.4, 62.7, 29.9, 3.7]]],
        'learnercert' => ['chapter' => 'learners', 'shot' => 'm_learner_cert', 'managers' => false, 'needs' => '',
            'pins' => [[80.8, 53.1, 15.8, 4.7], [80.8, 45.5, 15.8, 4.7]]],
        'groups' => ['chapter' => 'groups', 'shot' => 'm_groups', 'managers' => false, 'needs' => '',
            'pins' => [[2.4, 25.5, 40.6, 6.0], [43.0, 21.7, 15.5, 3.7]]],
        'groupnew' => ['chapter' => 'groups', 'shot' => 'm_group_new', 'managers' => false, 'needs' => '',
            'pins' => [[3.8, 20.1, 6.5, 2.8], [3.8, 25.0, 6.2, 2.8]]],
        'groupmembers' => ['chapter' => 'groups', 'shot' => 'm_group_members', 'managers' => false, 'needs' => '',
            'pins' => [[3.8, 23.0, 9.2, 1.8], [52.2, 23.0, 12.2, 1.8]]],
        'report' => ['chapter' => 'reports', 'shot' => 'm_report', 'managers' => false, 'needs' => '',
            'pins' => [[2.4, 24.5, 7.3, 3.7]]],
        'reportfilter' => ['chapter' => 'reports', 'shot' => 'm_report_course', 'managers' => false, 'needs' => '',
            'pins' => [[3.8, 19.8, 12.3, 3.4], [89.1, 13.5, 8.6, 3.4], [80.5, 13.5, 8.0, 3.4]]],
        'certs' => ['chapter' => 'certificates', 'shot' => 'm_certs', 'managers' => false, 'needs' => '',
            'pins' => [[87.8, 29.3, 6.0, 2.8], [83.3, 13.5, 14.4, 3.4]]],
        'onboarding' => ['chapter' => 'onboarding', 'shot' => 'm_onboarding', 'managers' => true, 'needs' => 'any',
            'pins' => [[2, 18.3, 96, 11.4], [91.1, 42.2, 3.8, 3.6], [71.9, 60.5, 15.5, 2.9]]],
        'onboardform' => ['chapter' => 'onboarding', 'shot' => 'm_onboard_form', 'managers' => true, 'needs' => 'any',
            'pins' => [[2.5, 34.2, 94.9, 4.2], [3.5, 55, 93.1, 31.5], [3.5, 86.5, 93.1, 8.4]]],
        'learnerrole' => ['chapter' => 'onboarding', 'shot' => 'm_learner_role', 'managers' => false, 'needs' => 'matrix',
            'pins' => [[2.1, 18.4, 95.8, 13.4], [2, 32.8, 47.6, 22.1], [50.4, 32.8, 47.6, 22.1]]],
        'compliance' => ['chapter' => 'compliance', 'shot' => 'm_compliance', 'managers' => false, 'needs' => 'matrix',
            'pins' => [[2, 18.3, 96, 11.4], [3.5, 32.1, 55.8, 4.2], [2.1, 42.1, 95.8, 5.6], [89.4, 13.2, 8.6, 4.2]]],
        'pathways' => ['chapter' => 'pathways', 'shot' => 'm_pathways', 'managers' => false, 'needs' => 'pathways',
            'pins' => [[2, 30.9, 31.5, 30.5], [79.2, 18.3, 18.8, 11.4]]],
        'pathwaypeople' => ['chapter' => 'pathways', 'shot' => 'm_pathway', 'managers' => false, 'needs' => 'pathways',
            'pins' => [[3.5, 6.4, 93.1, 18.7], [67.6, 37.5, 23.1, 3.6], [3.5, 78.2, 93.1, 9.5]]],
        'courses' => ['chapter' => 'coursebuilder', 'shot' => 'm_courses', 'managers' => true, 'needs' => 'clientcourses',
            'pins' => [[89.1, 13.2, 8.9, 4.2], [2, 30.9, 22.9, 36.7], [25.8, 35.5, 72.2, 7.6]]],
        'newcourse' => ['chapter' => 'coursebuilder', 'shot' => 'm_newcourse', 'managers' => true, 'needs' => 'clientcourses',
            'pins' => [[2.5, 19.9, 94.9, 4.2], [2.5, 34.7, 94.9, 4.2], [2.5, 62.5, 94.9, 3.1]]],
        'coursemanage' => ['chapter' => 'coursebuilder', 'shot' => 'm_coursemanage', 'managers' => true, 'needs' => 'clientcourses',
            'pins' => [[2, 29.1, 47.6, 46.2], [50.4, 29.1, 47.6, 46.2], [70.9, 13.2, 9.3, 4.2]]],
        'marking' => ['chapter' => 'marking', 'shot' => 'm_marking', 'managers' => false, 'needs' => 'marking',
            'pins' => [[2, 17.5, 96, 9.5], [87.6, 33.8, 4.4, 3.6]]],
        'trainers' => ['chapter' => 'marking', 'shot' => 'm_trainers', 'managers' => true, 'needs' => 'marking',
            'pins' => [[70.6, 22.1, 17.8, 4.5], [3.5, 56.3, 93.1, 6.8]]],
        'team' => ['chapter' => 'team', 'shot' => 'm_team', 'managers' => true, 'needs' => '',
            'pins' => [[3.8, 19.3, 7.1, 1.8], [3.8, 42.4, 6.7, 1.8]]],
        'private' => ['chapter' => 'privacy', 'shot' => 'm_private', 'managers' => false, 'needs' => '',
            'pins' => [[4.5, 29.6, 51.3, 2.1]]],
    ];

    /**
     * Guides this person can see.
     *
     * @param scope $scope
     * @return array
     */
    public static function guides(scope $scope): array {
        $out = [];
        foreach (self::GUIDES as $id => $guide) {
            if ($guide['managers'] && !$scope->is_manager()) {
                continue;
            }
            // Trainers only use their courses and marking.
            if ($scope->is_trainer() && !in_array($guide['chapter'], ['coursebuilder', 'marking'], true)) {
                continue;
            }
            if (!self::installed($guide['needs'], $scope)) {
                continue;
            }
            // Marking is for the people who see the Marking tab: managers, trainers and assessors.
            if ($guide['needs'] === 'marking' && !$scope->is_manager() && !$scope->is_trainer() && !$scope->is_assessor()) {
                continue;
            }
            $out[$id] = $guide;
        }
        return $out;
    }

    /**
     * Is what a guide needs installed?
     *
     * @param string $needs ''|matrix|pathways|any|clientcourses|marking
     * @param scope $scope
     * @return bool
     */
    protected static function installed(string $needs, scope $scope): bool {
        return match ($needs) {
            'clientcourses' => courses::has($scope->client, courses::FEATURE_COURSES),
            'marking' => courses::has($scope->client, courses::FEATURE_TEACHERS) ||
                courses::has($scope->client, courses::FEATURE_ASSESSORS),
            'matrix' => integration\matrix::available(),
            'pathways' => integration\pathways::available(),
            'any' => integration\matrix::available() || integration\pathways::available(),
            default => true,
        };
    }
}
