<?php
/**
 * What the server is: its name, its public IPs, its registration.
 */
class Modules_EmailSpamTester_Server
{
    /** The name Postfix says HELO with, which is what PTR should point at. */
    public static function hostname()
    {
        $name = trim((string)pm_ApiCli::callSbin('est-helper', ['hostname'], pm_ApiCli::RESULT_STDOUT));
        return preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/i', $name) ? strtolower($name) : null;
    }

    /** The server's IPs, with the public address Plesk knows behind NAT. */
    public static function ips()
    {
        $out = [];
        $list = pm_ApiCli::call('ipmanage', ['--ip_list'], pm_ApiCli::RESULT_STDOUT);
        foreach (preg_split('/\r?\n/', (string)$list) as $line) {
            if (preg_match('/\s\S+:([0-9a-f.:]+)\/\S+\s+\d+\s+\d+\s*(\S*)\s*$/i', $line, $m)) {
                $out[] = $m[1];
                if ($m[2] !== '' && filter_var($m[2], FILTER_VALIDATE_IP)) {
                    $out[] = $m[2];
                }
            }
        }
        return array_values(array_unique($out));
    }

    public static function register($adminEmail = null)
    {
        if (Modules_EmailSpamTester_Config::isRegistered()) {
            throw new pm_Exception('This server is already registered.');
        }
        $id = preg_replace('/[^A-Za-z0-9._:-]/', '', (string)self::hostname());
        $body = array_filter([
            'panel' => 'plesk',
            'server_id' => strlen($id) >= 8 ? $id : 'plesk-' . substr(hash('sha256', php_uname()), 0, 16),
            'panel_version' => substr((string)pm_ProductInfo::getVersion(), 0, 32),
            'plugin_version' => substr(Modules_EmailSpamTester_Api::version(), 0, 32),
            'accounts' => count(pm_Domain::getAllDomains(true)),
            'admin_email' => $adminEmail ?: null,
        ], function ($v) { return $v !== null; });
        $answer = (new Modules_EmailSpamTester_Api(''))->register($body);
        if (empty($answer['key']) || strpos($answer['key'], 'est_live_') !== 0) {
            throw new pm_Exception('Registration failed: no key in the answer.');
        }
        Modules_EmailSpamTester_Config::saveRegistration($answer['key'], $answer['installation_id']);
        pm_Log::info('Email Spam Tester: registered as installation ' . (int)$answer['installation_id']);
    }
}
