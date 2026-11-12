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
use moodle_url;
use stdClass;

/**
 * Xero: connect with OAuth 2.0, then once a week add each billed client's usage to a draft invoice (one draft per client
 * per month; the site owner reviews and approves it in Xero).
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class xero {
    /** Authorise endpoint. */
    public const AUTHORIZE = 'https://login.xero.com/identity/connect/authorize';
    /** Token endpoint. */
    public const TOKEN = 'https://identity.xero.com/connect/token';
    /** Connections (organisations) endpoint. */
    public const CONNECTIONS = 'https://api.xero.com/connections';
    /** Accounting API. */
    public const API = 'https://api.xero.com/api.xro/2.0/';

    /**
     * Callback URL to register in the Xero app.
     *
     * @return moodle_url
     */
    public static function redirect_uri(): moodle_url {
        return new moodle_url('/local/clientportal/admin/xero.php');
    }

    /**
     * Scopes to ask for. Apps made in Xero from March 2026 only accept the granular scopes.
     *
     * @return string
     */
    public static function scopes(): string {
        $invoices = get_config('local_clientportal', 'xeroscopes') === 'broad' ? 'accounting.transactions' : 'accounting.invoices';
        return 'offline_access ' . $invoices . ' accounting.contacts';
    }

    /**
     * Are the app details filled in?
     *
     * @return bool
     */
    public function configured(): bool {
        return (string)get_config('local_clientportal', 'xeroclientid') !== '' &&
            (string)get_config('local_clientportal', 'xeroclientsecret') !== '';
    }

    /**
     * Is an organisation connected?
     *
     * @return bool
     */
    public function connected(): bool {
        return $this->configured() && $this->tokens() !== null && (string)get_config('local_clientportal', 'xerotenantid') !== '';
    }

    /**
     * Link that starts the connection.
     *
     * @return moodle_url
     */
    public function connect_url(): moodle_url {
        return new moodle_url(self::AUTHORIZE, [
            'response_type' => 'code',
            'client_id' => get_config('local_clientportal', 'xeroclientid'),
            'redirect_uri' => self::redirect_uri()->out(false),
            'scope' => self::scopes(),
            'state' => sesskey(),
        ]);
    }

    /**
     * Finish connecting: swap the code for tokens and remember the organisation.
     *
     * @param string $code
     * @return string organisation name
     */
    public function finish_connect(string $code): string {
        $tokens = $this->token_request([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::redirect_uri()->out(false),
        ]);
        $this->save_tokens($tokens);
        $connections = $this->http('GET', self::CONNECTIONS, null, false);
        if (empty($connections[0]['tenantId'])) {
            throw new moodle_exception('xero_noorg', 'local_clientportal');
        }
        set_config('xerotenantid', $connections[0]['tenantId'], 'local_clientportal');
        set_config('xerotenantname', $connections[0]['tenantName'] ?? '', 'local_clientportal');
        return (string)($connections[0]['tenantName'] ?? '');
    }

    /**
     * Forget the connection.
     */
    public function disconnect(): void {
        foreach (['xerotokens', 'xerotenantid', 'xerotenantname'] as $name) {
            unset_config($name, 'local_clientportal');
        }
    }

    /**
     * Stored tokens.
     *
     * @return array|null access_token, refresh_token, expires
     */
    protected function tokens(): ?array {
        $stored = (string)get_config('local_clientportal', 'xerotokens');
        if ($stored === '') {
            return null;
        }
        try {
            $data = json_decode(\core\encryption::decrypt($stored), true);
        } catch (\Throwable $e) {
            return null;
        }
        return is_array($data) && !empty($data['refresh_token']) ? $data : null;
    }

    /**
     * Store tokens, encrypted. Xero issues a new refresh token every time; the old one stops working.
     *
     * @param array $response token endpoint response
     */
    protected function save_tokens(array $response): void {
        if (empty($response['access_token']) || empty($response['refresh_token'])) {
            throw new moodle_exception('xero_tokenfailed', 'local_clientportal', '', $response['error'] ?? '');
        }
        $data = [
            'access_token' => $response['access_token'],
            'refresh_token' => $response['refresh_token'],
            'expires' => time() + (int)($response['expires_in'] ?? 1800) - 60,
        ];
        set_config('xerotokens', \core\encryption::encrypt(json_encode($data)), 'local_clientportal');
    }

    /**
     * A current access token (refreshed when needed).
     *
     * @return string
     */
    protected function access_token(): string {
        $tokens = $this->tokens();
        if (!$tokens) {
            throw new moodle_exception('xero_notconnected', 'local_clientportal');
        }
        if ((int)$tokens['expires'] > time()) {
            return $tokens['access_token'];
        }
        $response = $this->token_request(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']]);
        $this->save_tokens($response);
        return $response['access_token'];
    }

    /**
     * POST to the token endpoint.
     *
     * @param array $params
     * @return array
     */
    protected function token_request(array $params): array {
        $curl = new \curl();
        $curl->setHeader([
            'Authorization: Basic ' . base64_encode(get_config('local_clientportal', 'xeroclientid') . ':' .
                get_config('local_clientportal', 'xeroclientsecret')),
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ]);
        $body = $curl->post(self::TOKEN, http_build_query($params, '', '&'));
        $data = json_decode((string)$body, true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new moodle_exception('xero_tokenfailed', 'local_clientportal', '', is_array($data) ? ($data['error'] ?? '') : '');
        }
        return $data;
    }

    /**
     * Call Xero.
     *
     * @param string $method GET|POST
     * @param string $url
     * @param array|null $body
     * @param bool $tenant send the organisation header
     * @return array
     */
    protected function http(string $method, string $url, ?array $body = null, bool $tenant = true): array {
        $curl = new \curl();
        $headers = ['Authorization: Bearer ' . $this->access_token(), 'Accept: application/json'];
        if ($tenant) {
            $headers[] = 'xero-tenant-id: ' . get_config('local_clientportal', 'xerotenantid');
        }
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $curl->setHeader($headers);
        $raw = $method === 'GET' ? $curl->get($url) : $curl->post($url, json_encode($body));
        $info = $curl->get_info();
        $code = is_array($info) ? (int)($info['http_code'] ?? 200) : 200;
        $data = json_decode((string)$raw, true);
        if ($code >= 400 || !is_array($data)) {
            $message = is_array($data) ? ($data['Detail'] ?? $data['Message'] ?? $data['Title'] ?? '') : (string)$raw;
            if (is_array($data) && !empty($data['Elements'][0]['ValidationErrors'][0]['Message'])) {
                $message = $data['Elements'][0]['ValidationErrors'][0]['Message'];
            }
            throw new moodle_exception('xero_apierror', 'local_clientportal', '', \core_text::substr(trim($code . ' ' .
                $message), 0, 300));
        }
        return $data;
    }

    /**
     * The client's Xero contact, found by name or created.
     *
     * @param stdClass $client
     * @return string ContactID
     */
    public function contact(stdClass $client): string {
        global $DB;
        if (!empty($client->xerocontact)) {
            return $client->xerocontact;
        }
        $name = str_replace('"', '', $client->name);
        $found = $this->http('GET', self::API . 'Contacts?where=' . rawurlencode('Name=="' . $name . '"'));
        $id = $found['Contacts'][0]['ContactID'] ?? '';
        if ($id === '') {
            $new = ['Name' => $client->name];
            if (!empty($client->contactemail)) {
                $new['EmailAddress'] = $client->contactemail;
            }
            $made = $this->http('POST', self::API . 'Contacts', ['Contacts' => [$new]]);
            $id = $made['Contacts'][0]['ContactID'] ?? '';
        }
        if ($id === '') {
            throw new moodle_exception('xero_nocontact', 'local_clientportal', '', format_string($client->name));
        }
        $DB->set_field('local_clientportal_client', 'xerocontact', $id, ['id' => $client->id]);
        $client->xerocontact = $id;
        return $id;
    }

    /**
     * Bill last week (or the week before $now) for every client with billing on.
     *
     * @param int|null $now
     * @return string[] one line per client, for the task log and the admin page
     */
    public function bill_week(?int $now = null): array {
        $to = usage::week_start($now ?? time());
        $from = $to - 7 * DAYSECS;
        $report = [];
        foreach (manager::get_clients() as $client) {
            if (empty($client->billing)) {
                continue;
            }
            $report[] = format_string($client->name) . ': ' . $this->bill_client($client, $from, $to);
        }
        return $report;
    }

    /**
     * Add one period of a client's usage to their draft invoice for that month.
     *
     * @param stdClass $client
     * @param int $from
     * @param int $to
     * @return string what happened
     */
    public function bill_client(stdClass $client, int $from, int $to): string {
        global $DB;
        $billedto = (int)get_config('local_clientportal', 'billedto_' . $client->id);
        if ($billedto >= $to) {
            return get_string('xero_alreadybilled', 'local_clientportal');
        }
        $from = max($from, $billedto);
        $usage = usage::summary($from, $to, (int)$client->id)[(int)$client->id];
        if (!$usage->lines) {
            set_config('billedto_' . $client->id, $to, 'local_clientportal');
            return get_string('xero_nothing', 'local_clientportal');
        }
        $monthstart = make_timestamp((int)userdate($from, '%Y'), (int)userdate($from, '%m'), 1, 0, 0, 0);
        $invoice = $DB->get_record_select(
            'local_clientportal_invoice',
            "clientid = ? AND periodstart >= ? AND status = 'draft' AND xeroinvoiceid <> ''",
            [$client->id, $monthstart],
            '*',
            IGNORE_MULTIPLE
        );
        $now = time();
        if (!$invoice) {
            $invoice = (object)['clientid' => $client->id, 'periodstart' => $from, 'periodend' => $to, 'lines' => '[]',
                'total' => 0, 'xeroinvoiceid' => '', 'xeronumber' => '', 'status' => 'draft', 'error' => null,
                'timecreated' => $now, 'timemodified' => $now];
            $invoice->id = $DB->insert_record('local_clientportal_invoice', $invoice);
        }
        $lines = array_merge(json_decode((string)$invoice->lines, true) ?: [], $usage->lines);
        try {
            $contact = $this->contact($client);
            $account = (string)get_config('local_clientportal', 'xeroaccount') ?: '200';
            $payload = [
                'Type' => 'ACCREC',
                'Contact' => ['ContactID' => $contact],
                'Date' => userdate($now, '%Y-%m-%d'),
                'DueDate' => userdate($now + 14 * DAYSECS, '%Y-%m-%d'),
                'Status' => 'DRAFT',
                'LineAmountTypes' => 'Exclusive',
                'Reference' => get_string('xero_reference', 'local_clientportal', userdate($from, '%B %Y')),
                'LineItems' => array_map(fn($l) => [
                    'Description' => $l['description'],
                    'Quantity' => $l['quantity'],
                    'UnitAmount' => $l['unitamount'],
                    'AccountCode' => $account,
                ], $lines),
            ];
            if ($invoice->xeroinvoiceid !== '') {
                $payload['InvoiceID'] = $invoice->xeroinvoiceid;
            }
            $result = $this->http('POST', self::API . 'Invoices', ['Invoices' => [$payload]]);
            $saved = $result['Invoices'][0] ?? [];
            if (empty($saved['InvoiceID'])) {
                throw new moodle_exception('xero_apierror', 'local_clientportal', '', (string)($result['Message'] ??
                    $result['Detail'] ?? ''));
            }
            $invoice->xeroinvoiceid = (string)($saved['InvoiceID'] ?? $invoice->xeroinvoiceid);
            $invoice->xeronumber = (string)($saved['InvoiceNumber'] ?? $invoice->xeronumber);
            $invoice->lines = json_encode($lines);
            $invoice->total = round(array_sum(array_map(fn($l) => $l['quantity'] * $l['unitamount'], $lines)), 2);
            $invoice->periodend = $to;
            $invoice->status = 'draft';
            $invoice->error = null;
            $invoice->timemodified = time();
            $DB->update_record('local_clientportal_invoice', $invoice);
            set_config('billedto_' . $client->id, $to, 'local_clientportal');
            manager::log((int)$client->id, 'invoice_lines_added', null, $invoice->xeronumber . ' ' . $invoice->total);
            return get_string('xero_added', 'local_clientportal', ['lines' => count($usage->lines),
                'number' => $invoice->xeronumber ?: $invoice->xeroinvoiceid]);
        } catch (moodle_exception $e) {
            if ($invoice->xeroinvoiceid === '') {
                $invoice->status = 'failed';
            }
            $invoice->error = $e->getMessage();
            $invoice->timemodified = time();
            $DB->update_record('local_clientportal_invoice', $invoice);
            return get_string('xero_failed', 'local_clientportal', $e->getMessage());
        }
    }
}
