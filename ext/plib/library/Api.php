<?php
/**
 * The Email Spam Tester API, as this extension uses it. Every judgement is
 * made there; this class only carries requests.
 */
class Modules_EmailSpamTester_Api
{
    const BASE = 'https://email-spam-tester.com';
    /** Where test and monitoring mail goes. Not configurable on purpose: an
     *  address outside it is refused before anything is sent (Tests, Monitor). */
    const MAIL_DOMAIN = 't.email-spam-tester.com';
    const TIMEOUT = 25;

    private $key;

    public function __construct($key = null)
    {
        $this->key = $key === null ? Modules_EmailSpamTester_Config::key() : $key;
    }

    public static function reportUrl($slug)
    {
        return self::BASE . '/t/' . rawurlencode($slug);
    }

    // --- registration -----------------------------------------------------

    public function register(array $body)
    {
        return $this->call('POST', '/api/v1/installations', null, $body, false);
    }

    // --- tests ------------------------------------------------------------

    public function reserve($account, array $meta)
    {
        return $this->call('POST', '/api/v1/inbox', ['lang' => 'en', 'account' => $account] + $meta);
    }

    public function createPlacement($account, $slug)
    {
        return $this->call('POST', '/api/v1/placement', null,
            ['public_results_accepted' => true, 'score_slug' => $slug, 'account' => $account]);
    }

    /** Long-polls up to $wait seconds for a change; null while no email has arrived. */
    public function status($slug, $wait = 0)
    {
        list($code, $data) = $this->raw('GET', '/api/v1/tests/' . rawurlencode($slug) . '/status',
            $wait ? ['wait' => (int)$wait] : null, null, true, self::TIMEOUT + (int)$wait);
        if ($code === 202) {
            return null;
        }
        return $this->decode($code, $data);
    }

    public function report($slug)
    {
        list($code, $data) = $this->raw('GET', '/api/v1/tests/' . rawurlencode($slug), ['lang' => 'en']);
        if ($code === 202) {
            return null;
        }
        return $this->decode($code, $data);
    }


    public function placement($slug)
    {
        return $this->call('GET', '/api/v1/placement/' . rawurlencode($slug));
    }


    public function history($account, $domain = null, $before = null, $limit = 50)
    {
        return $this->call('GET', '/api/v1/installations/tests',
            array_filter(['account' => $account, 'domain' => $domain, 'before' => $before, 'limit' => $limit]));
    }

    public function postDelivery($slug, $account, array $lines)
    {
        return $this->call('POST', '/api/v1/installations/tests/' . rawurlencode($slug) . '/delivery',
            ['account' => $account], ['mta' => 'postfix', 'lines' => array_values($lines)]);
    }

    public function domainCheck($account, array $body)
    {
        return $this->call('POST', '/api/v1/installations/domains/check', ['account' => $account], $body);
    }

    // --- limits -----------------------------------------------------------

    public function limits()
    {
        return $this->call('GET', '/api/v1/installations/limits');
    }

    public function setLimits(array $limits)
    {
        return $this->call('PUT', '/api/v1/installations/limits', null, $limits);
    }

    // --- monitoring -------------------------------------------------------

    public function openMailbox($account, $domain)
    {
        return $this->call('POST', '/api/v1/installations/mailboxes', null,
            ['domain' => $domain, 'account' => $account, 'lang' => 'en']);
    }

    public function closeMailbox($id, $account)
    {
        return $this->call('DELETE', '/api/v1/installations/mailboxes/' . (int)$id, ['account' => $account]);
    }

    public function feed($id, $account, $limit = 30)
    {
        return $this->call('GET', '/api/v1/installations/mailboxes/' . (int)$id . '/feed',
            ['account' => $account, 'limit' => $limit]);
    }


    public function letter($slug, $account)
    {
        return $this->call('GET', '/api/v1/installations/letters/' . rawurlencode($slug),
            ['account' => $account, 'lang' => 'en']);
    }

    public function advise($slug, $account)
    {
        return $this->call('POST', '/api/v1/installations/letters/' . rawurlencode($slug) . '/advise',
            ['account' => $account], new stdClass());
    }

    // --- plumbing ---------------------------------------------------------

    private function call($method, $path, $query = null, $body = null, $auth = true)
    {
        list($code, $data) = $this->raw($method, $path, $query, $body, $auth);
        return $this->decode($code, $data);
    }

    private function decode($code, $data)
    {
        if ($code >= 200 && $code < 300 && is_array($data)) {
            return $data;
        }
        $detail = is_array($data) && isset($data['detail']) ? $data['detail'] : null;
        if (is_array($detail)) {
            $detail = isset($detail['message']) ? $detail['message'] : (isset($detail['error']) ? $detail['error'] : json_encode($detail));
        }
        if ($code === 0) {
            $detail = 'no connection to Email Spam Tester (' . $detail . ')';
        }
        throw new Modules_EmailSpamTester_ApiException($detail ?: "HTTP $code", $code);
    }

    private function raw($method, $path, $query = null, $body = null, $auth = true, $timeout = self::TIMEOUT)
    {
        if ($auth && $this->key === '') {
            throw new Modules_EmailSpamTester_ApiException('This server is not registered with Email Spam Tester yet.', 401);
        }
        $url = self::BASE . $path . ($query ? '?' . http_build_query($query) : '');
        $headers = ['Accept: application/json'];
        if ($auth) {
            $headers[] = 'Authorization: Bearer ' . $this->key;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            // Never carry the key to wherever a redirect points.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'EmailSpamTester-Plesk/' . self::version(),
        ]);
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        } elseif ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            return [0, ['detail' => $error]];
        }
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return [$code, json_decode($response, true)];
    }

    public static function version()
    {
        $info = pm_Context::getModuleInfo();
        return $info && isset($info->version) ? $info->version : 'dev';
    }
}
