<?php
/**
 * Constant monitoring: the server's rule (root, est-monitor) and the domains
 * switched on (the API's permanent addresses). Feed, reports and plans are
 * read from the API with the domain's account, so a customer sees only their
 * own domains' mail.
 */
class Modules_EmailSpamTester_Monitor
{
    const STATE = 'monitor_domains';
    const ADDRESS_RE = '/^m-[a-z0-9]{20}@t\.email-spam-tester\.com$/';

    public static function serverStatus()
    {
        return self::root(['status']);
    }

    public static function allow($dryRun = false)
    {
        $out = self::root($dryRun ? ['enable', '--dry-run'] : ['enable']);
        if (!$dryRun) {
            self::push();
        }
        return $out;
    }

    /** Off on the whole server: the rule out, every domain switched off at the API. */
    public static function disallow()
    {
        $out = self::root(['disable']);
        foreach (self::domains() as $name => $entry) {
            self::close($entry);
        }
        pm_Settings::set(self::STATE, json_encode([]));
        return $out;
    }

    /** Every change to the list under one lock: two customers switching at once lose nothing. */
    private static function locked(callable $fn)
    {
        $fh = fopen(pm_Context::getVarDir() . 'monitor.lock', 'c');
        flock($fh, LOCK_EX);
        try {
            return $fn();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    public static function domains()
    {
        $data = json_decode((string)pm_Settings::get(self::STATE, '{}'), true);
        return is_array($data) ? $data : [];
    }

    public static function entry(pm_Domain $domain)
    {
        $all = self::domains();
        return isset($all[$domain->getName()]) ? $all[$domain->getName()] : null;
    }

    public static function on(pm_Domain $domain)
    {
        if (self::serverStatus()['state'] !== 'active') {
            throw new pm_Exception('The server administrator has not switched constant monitoring on for this server.');
        }
        $account = Modules_EmailSpamTester_Config::account($domain);
        $box = (new Modules_EmailSpamTester_Api())->openMailbox($account, $domain->getName());
        $address = strtolower(isset($box['address']) ? (string)$box['address'] : '');
        if (!preg_match(self::ADDRESS_RE, $address) || empty($box['id'])) {
            throw new pm_Exception('Email Spam Tester returned an unexpected address.');
        }
        self::locked(function () use ($domain, $box, $address, $account) {
            $all = self::domains();
            $all[$domain->getName()] = ['id' => (int)$box['id'], 'address' => $address, 'account' => $account,
                                        'guid' => $domain->getGuid(), 'domain_id' => $domain->getId(), 'on_at' => time()];
            pm_Settings::set(self::STATE, json_encode($all));
        });
        self::push();
    }

    /** Off: out of the rule's list first, so no copy leaves after this. */
    public static function off(pm_Domain $domain)
    {
        $entry = self::locked(function () use ($domain) {
            $all = self::domains();
            $entry = isset($all[$domain->getName()]) ? $all[$domain->getName()] : null;
            unset($all[$domain->getName()]);
            pm_Settings::set(self::STATE, json_encode($all));
            return $entry;
        });
        self::push();
        if ($entry) {
            self::close($entry);
        }
    }

    /** Domains that are gone or moved to another subscription are switched off (daily, and on events). */
    /**
     * Domains that are gone or moved are switched off. With $refresh (the daily
     * task, an upgrade) the rule is also checked and repaired; a domain event
     * only updates the list, without touching Postfix.
     */
    public static function reconcile($refresh = true)
    {
        foreach (self::domains() as $name => $entry) {
            if (self::current($name, $entry)) {
                continue;
            }
            self::locked(function () use ($name) {
                $all = self::domains();
                unset($all[$name]);
                pm_Settings::set(self::STATE, json_encode($all));
            });
            self::close($entry);
        }
        if ($refresh && self::serverStatus()['state'] !== 'off') {
            self::root(['refresh']);
        }
        self::push();
    }

    public static function feed(pm_Domain $domain)
    {
        $entry = self::entry($domain);
        return $entry ? (new Modules_EmailSpamTester_Api())->feed($entry['id'], $entry['account']) : null;
    }


    /** The domain still exists under this name, is the same domain, in the same subscription. */
    private static function current($name, array $entry)
    {
        try {
            $domain = pm_Domain::getByName($name);
        } catch (Exception $e) {
            return false;
        }
        return (!isset($entry['guid']) || $domain->getGuid() === $entry['guid'])
            && Modules_EmailSpamTester_Config::account($domain) === $entry['account'];
    }

    /** The rule's list: each domain's address, and the system users whose PHP mail is theirs. */
    private static function push()
    {
        if (self::serverStatus()['state'] !== 'active') {
            return;
        }
        self::locked(function () {
            self::pushLocked();
        });
    }

    private static function pushLocked()
    {
        $domains = [];
        $uids = [];
        foreach (self::domains() as $name => $entry) {
            // A renamed or removed domain is left out at once, whatever reconcile has not done yet.
            if (!self::current($name, $entry)) {
                continue;
            }
            $domains[$name] = ['address' => $entry['address']];
            try {
                $sysuser = Modules_EmailSpamTester_Config::subscriptionDomain(pm_Domain::getByName($name))->getSysUserLogin();
                $pw = $sysuser ? posix_getpwnam($sysuser) : false;
                if ($pw && $pw['uid'] >= 1000) {
                    $uids[(string)$pw['uid']][] = $name;
                }
            } catch (Exception $e) {
                continue;
            }
        }
        self::root(['set'], json_encode(['domains' => (object)$domains, 'uid_domains' => (object)$uids]));
    }

    private static function close(array $entry)
    {
        try {
            (new Modules_EmailSpamTester_Api())->closeMailbox($entry['id'], $entry['account']);
        } catch (Exception $e) {
            pm_Log::warn('Email Spam Tester: closing a monitor address failed: ' . $e->getMessage());
        }
    }

    private static function root(array $args, $stdin = '')
    {
        $result = pm_ApiCli::callSbin('est-monitor', $args, pm_ApiCli::RESULT_FULL, [], $stdin);
        $data = json_decode((string)$result['stdout'], true);
        if (!is_array($data)) {
            throw new pm_Exception('The monitoring tool did not answer: ' . trim($result['stderr']));
        }
        if (isset($data['error'])) {
            throw new pm_Exception($data['error']);
        }
        return $data;
    }
}
