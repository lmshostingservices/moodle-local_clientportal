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

use html_writer;
use moodle_url;
use stdClass;

/**
 * Branded sign-in: each client has a private sign-in link that shows its logo and colours on the login page.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class branding {
    /** @var string Session key for the client whose sign-in page is showing. */
    private const SESSIONKEY = 'local_clientportal_loginclient';

    /**
     * A key that cannot be guessed, so client names cannot be found by trying ids.
     *
     * @param int $clientid
     * @return string
     */
    public static function login_key(int $clientid): string {
        return substr(hash_hmac('sha256', 'login:' . $clientid, get_site_identifier()), 0, 12);
    }

    /**
     * The client's sign-in link.
     *
     * @param int $clientid
     * @return moodle_url
     */
    public static function login_url(int $clientid): moodle_url {
        return new moodle_url('/local/clientportal/login.php', ['c' => $clientid . '-' . self::login_key($clientid)]);
    }

    /**
     * The client a sign-in link points to, or null when the link is wrong or the client is inactive.
     *
     * @param string $param the c= value
     * @return stdClass|null
     */
    public static function client_from_param(string $param): ?stdClass {
        if (!preg_match('/^(\d+)-([a-f0-9]{12})$/', $param, $m)) {
            return null;
        }
        $clientid = (int)$m[1];
        if (!hash_equals(self::login_key($clientid), $m[2])) {
            return null;
        }
        $client = manager::get_client($clientid);
        return $client && !empty($client->active) ? $client : null;
    }

    /**
     * Remember which client's sign-in page to show.
     *
     * @param int $clientid 0 to forget
     */
    public static function remember(int $clientid): void {
        global $SESSION;
        if ($clientid) {
            $SESSION->{self::SESSIONKEY} = $clientid;
        } else {
            unset($SESSION->{self::SESSIONKEY});
        }
    }

    /**
     * The client to brand the current sign-in page for (only before sign-in, only on login pages).
     *
     * @return stdClass|null client with ->branding (Cohort Branding record or null)
     */
    private static function current(): ?stdClass {
        global $SESSION, $PAGE;
        if (during_initial_install() || isloggedin() && !isguestuser()) {
            return null;
        }
        $clientid = (int)($SESSION->{self::SESSIONKEY} ?? 0);
        if (!$clientid || ($PAGE->pagelayout ?? '') !== 'login') {
            return null;
        }
        $client = manager::get_client($clientid);
        if (!$client || empty($client->active)) {
            return null;
        }
        $client->branding = null;
        if (class_exists('\\local_cohortbranding\\manager')) {
            try {
                $branding = \local_cohortbranding\manager::get_branding_by_cohort((int)$client->cohortid);
                $client->branding = $branding && !empty($branding->enabled) ? $branding : null;
            } catch (\Throwable $e) {
                debugging('local_clientportal: branding lookup failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        return $client;
    }

    /**
     * Hook: the client's colours, font and logo on its sign-in page.
     *
     * @param \core\hook\output\before_standard_head_html_generation $hook
     */
    public static function head(\core\hook\output\before_standard_head_html_generation $hook): void {
        $client = self::current();
        if (!$client || (!$client->branding && !self::is_lmslabs_theme())) {
            return;
        }
        $css = '';
        $primary = '';
        if ($client->branding) {
            $css = \local_cohortbranding\manager::generate_css($client->branding);
            $primary = \local_cohortbranding\manager::validate_hex_color((string)($client->branding->primarycolor ?? ''));
        }
        if ($primary) {
            $text = \local_cohortbranding\manager::contrast_text($primary);
            $css .= "\n.cport-loginbrand{border-top:4px solid {$primary};}" .
                "\n#page-login-index .btn-primary{background-color:{$primary};border-color:{$primary};color:{$text};}";
        }
        if (self::is_lmslabs_theme()) {
            $css .= self::lmslabs_css($client, $primary);
        }
        $hook->add_html(html_writer::tag('style', str_replace('</', '<\/', $css)));
    }

    /**
     * Is the Premium SaaS theme drawing the sign-in page? It has its own brand panel, so that is restyled instead
     * of adding a banner.
     *
     * @return bool
     */
    private static function is_lmslabs_theme(): bool {
        global $PAGE;
        return ($PAGE->theme->name ?? '') === 'lmslabs';
    }

    /**
     * Restyle the Premium SaaS theme's sign-in page for the client: panel colour, logos and the client's name.
     *
     * @param stdClass $client
     * @param string $primary validated colour or ''
     * @return string CSS
     */
    private static function lmslabs_css(stdClass $client, string $primary): string {
        $name = self::css_string(format_string($client->name, true, ['escape' => false]));
        $signin = self::css_string(get_string('signinto', 'local_clientportal', format_string(
            $client->name,
            true,
            ['escape' => false]
        )));
        $css = "\nbody.ll-login .ll-auth-title{font-size:0;}" .
            "\nbody.ll-login .ll-auth-title::after{content:{$name};font-size:2.25rem;}" .
            "\nbody.ll-login .loginform p.text-muted{font-size:0;}" .
            "\nbody.ll-login .loginform p.text-muted::after{content:{$signin};font-size:1rem;}";
        if ($primary) {
            $css .= "\nbody.ll-login .ll-auth-brand{background-color:{$primary};}" .
                "\nbody.ll-login .loginform a{color:{$primary};}";
        }
        $logo = !empty($client->branding->logourl)
            ? \local_cohortbranding\manager::validate_url((string)$client->branding->logourl) : '';
        if ($logo) {
            $url = self::css_string($logo);
            $css .= "\nbody.ll-login .ll-auth-logo img,body.ll-login .loginform img.img-fluid{content:url({$url});" .
                "object-fit:contain;}";
        }
        return $css;
    }

    /**
     * Quote text as a CSS string.
     *
     * @param string $text
     * @return string
     */
    private static function css_string(string $text): string {
        $out = '';
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $char) {
            $out .= preg_match('/[A-Za-z0-9 .,:\-]/', $char) ? $char : sprintf('\\%X ', \core_text::utf8ord($char));
        }
        return '"' . $out . '"';
    }

    /**
     * Hook: "Sign in to <client>" with the client's logo above the sign-in form.
     *
     * @param \core\hook\output\before_standard_top_of_body_html_generation $hook
     */
    public static function top_of_body(\core\hook\output\before_standard_top_of_body_html_generation $hook): void {
        $client = self::current();
        if (!$client || self::is_lmslabs_theme()) {
            return;
        }
        $logo = '';
        if ($client->branding && !empty($client->branding->logourl)) {
            $url = \local_cohortbranding\manager::validate_url((string)$client->branding->logourl);
            if ($url) {
                $logo = html_writer::empty_tag('img', ['src' => $url, 'alt' => '', 'class' => 'cport-loginbrand-logo']);
            }
        }
        $title = html_writer::div(
            get_string('signinto', 'local_clientportal', format_string($client->name)),
            'cport-loginbrand-title'
        );
        $hook->add_html(html_writer::div($logo . $title, 'cport-loginbrand', ['role' => 'banner']));
    }
}
