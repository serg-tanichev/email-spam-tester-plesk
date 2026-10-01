<?php
/**
 * Fixes for a domain, as the Email Spam Tester API decides them.
 *
 * The extension tells the API what Plesk holds (its zone, its DKIM key, the
 * server's IPs and name) and applies what comes back exactly as given, after
 * the user confirmed it. It keeps only what it needs to undo its own change.
 */
class Modules_EmailSpamTester_Fixes
{
    const LOG = 'fixes';
    const KEEP = 50;

    /** What the panel holds, in the shape the API's domain check takes. */
    public static function context(pm_Domain $domain, $report = null)
    {
        $name = $domain->getName();
        $zone = Modules_EmailSpamTester_Dns::zone($domain);
        $txt = function ($host, $prefix) use ($domain) {
            return array_map(function ($r) { return $r->getValue(); },
                Modules_EmailSpamTester_Dns::zoneTxt($domain, $host, $prefix));
        };
        $ns = [];
        if ($zone) {
            foreach ($zone->getRecordsByType(pm_Dns_Record::TYPE_NS) as $record) {
                if (Modules_EmailSpamTester_Dns::fqdn($record->getHost()) === Modules_EmailSpamTester_Dns::fqdn($name)) {
                    $ns[] = rtrim($record->getValue(), '.');
                }
            }
        }
        $dkim = $txt("default._domainkey.$name", 'v=DKIM1');
        $rua = null;
        foreach (Modules_EmailSpamTester_MailInfo::mailboxes($domain) as $box) {
            $rua = $box;
            break;
        }
        return array_filter([
            'domain' => $name,
            'ips' => array_values(array_unique(array_merge(
                $domain->getIpAddresses(), Modules_EmailSpamTester_Server::ips()))),
            'helo' => Modules_EmailSpamTester_Server::hostname(),
            'dkim' => ['selector' => 'default', 'enabled' => self::dkimEnabled($domain),
                       'record' => $dkim ? $dkim[0] : null],
            'zone' => ['managed' => Modules_EmailSpamTester_Dns::zoneManaged($domain), 'ns' => $ns,
                       'spf' => $txt($name, 'v=spf1'), 'dmarc' => $txt("_dmarc.$name", 'v=DMARC1'),
                       'dkim' => $dkim],
            'rua' => $rua,
            'report' => $report,
        ], function ($v) { return $v !== null; });
    }

    const CACHE = 'check_cache';
    const CACHE_SECONDS = 600;
    const PREFIXES = ['v=spf1', 'v=DMARC1', 'v=DKIM1'];

    public static function check(pm_Domain $domain, $report = null)
    {
        return (new Modules_EmailSpamTester_Api())->domainCheck(
            Modules_EmailSpamTester_Config::account($domain), self::context($domain, $report));
    }

    /** The domain's check, reused for ten minutes: reloading a page is not a new check. */
    public static function cachedCheck(pm_Domain $domain, $report = null)
    {
        $cached = json_decode((string)$domain->getSetting(self::CACHE, ''), true);
        if (is_array($cached) && isset($cached['at']) && $cached['at'] > time() - self::CACHE_SECONDS
            && (isset($cached['report']) ? $cached['report'] : null) === $report) {
            return $cached['data'];
        }
        $data = self::check($domain, $report);
        $domain->setSetting(self::CACHE, json_encode(['at' => time(), 'report' => $report, 'data' => $data]));
        return $data;
    }

    private static function forgetCheck(pm_Domain $domain)
    {
        $domain->setSetting(self::CACHE, '');
    }

    public static function dkimEnabled(pm_Domain $domain)
    {
        $out = pm_ApiCli::call('domain_pref', ['--info', $domain->getName()], pm_ApiCli::RESULT_STDOUT);
        return (bool)preg_match('/^DKIM Sign Outgoing Mail:\s+true/mi', $out);
    }

    /**
     * Apply one fix of a fresh check. Checked again now, so what is applied
     * is what the API says today, not what a preview said a while ago.
     */
    public static function apply(pm_Domain $domain, $id, $report = null)
    {
        $fix = null;
        foreach (self::check($domain, $report)['fixes'] as $candidate) {
            if ($candidate['id'] === $id) {
                $fix = $candidate;
            }
        }
        if (!$fix || $fix['mode'] !== 'apply') {
            throw new pm_Exception('This fix no longer applies. Run the check again.');
        }
        $undo = ['setting' => null, 'created' => [], 'updated' => [], 'removed' => []];
        if ($fix['action'] === 'enable_dkim') {
            pm_ApiCli::call('domain_pref', ['--update', $domain->getName(), '-sign_outgoing_mail', 'true']);
            $undo['setting'] = ['dkim' => 'false'];
        } elseif ($fix['action'] === 'set_txt' && is_string($fix['after']) && $fix['after'] !== '') {
            $host = strtolower(rtrim($fix['host'], '.'));
            $name = strtolower($domain->getName());
            if ($host !== $name && substr($host, -strlen($name) - 1) !== '.' . $name) {
                throw new pm_Exception('The fix is for another domain.');
            }
            $prefix = null;
            foreach (self::PREFIXES as $known) {
                if (stripos($fix['after'], $known) === 0) {
                    $prefix = $known;
                }
            }
            if ($prefix === null) {
                throw new pm_Exception('This record is not one the panel changes.');
            }
            $records = Modules_EmailSpamTester_Dns::zoneTxt($domain, $host, $prefix);
            if (!$records) {
                $undo['created'][] = Modules_EmailSpamTester_Dns::create($domain, $host, $fix['after']);
            } else {
                $first = array_shift($records);
                $undo['updated'][] = [$first->getId(), $first->getValue()];
                Modules_EmailSpamTester_Dns::update($first->getId(), $fix['after']);
                foreach ($records as $extra) {
                    $undo['removed'][] = [$host, $extra->getValue()];
                    Modules_EmailSpamTester_Dns::remove($extra->getId());
                }
            }
        } else {
            throw new pm_Exception('This fix cannot be applied from the panel.');
        }
        $entry = ['id' => bin2hex(random_bytes(6)), 'fix' => $fix['id'], 'action' => $fix['action'],
                  'host' => $fix['host'], 'before' => $fix['before'], 'after' => $fix['after'],
                  'at' => time(), 'by' => self::who(), 'undo' => $undo];
        self::log($domain, $entry);
        self::forgetCheck($domain);
        pm_ActionLog::submit('fix_applied', $domain->getId(),
            ['fix' => $fix['id'], 'value' => json_encode($fix['before'])],
            ['fix' => $fix['id'], 'value' => json_encode($fix['after'])]);
        return $entry;
    }

    /**
     * Undo one change, all of it or nothing, and only while the records are
     * still what the change left: a later edit by hand or a later fix is
     * never overwritten.
     */
    public static function revert(pm_Domain $domain, $entryId)
    {
        $log = self::history($domain);
        foreach ($log as &$entry) {
            if ($entry['id'] !== $entryId || !empty($entry['reverted_at'])) {
                continue;
            }
            $undo = $entry['undo'];
            $zone = Modules_EmailSpamTester_Dns::zone($domain);
            $zoneId = $zone ? $zone->getId() : null;
            $current = function ($id) use ($zoneId) {
                try {
                    $record = pm_Dns_Record::getById((int)$id);
                } catch (Exception $e) {
                    return null;
                }
                return $record->getZone()->getId() === $zoneId ? $record->getValue() : null;
            };
            foreach ($undo['created'] as $id) {
                if ($current($id) === null || Modules_EmailSpamTester_Dns::normalise($current($id)) !== Modules_EmailSpamTester_Dns::normalise($entry['after'])) {
                    throw new pm_Exception('The record has changed since; undo it by hand if you need to.');
                }
            }
            foreach ($undo['updated'] as $pair) {
                if ($current($pair[0]) === null || Modules_EmailSpamTester_Dns::normalise($current($pair[0])) !== Modules_EmailSpamTester_Dns::normalise($entry['after'])) {
                    throw new pm_Exception('The record has changed since; undo it by hand if you need to.');
                }
            }
            if (!empty($undo['setting']['dkim'])) {
                pm_ApiCli::call('domain_pref', ['--update', $domain->getName(), '-sign_outgoing_mail', $undo['setting']['dkim']]);
            }
            foreach ($undo['created'] as $id) {
                Modules_EmailSpamTester_Dns::remove($id);
            }
            foreach ($undo['updated'] as $pair) {
                Modules_EmailSpamTester_Dns::update($pair[0], $pair[1]);
            }
            foreach ($undo['removed'] as $pair) {
                Modules_EmailSpamTester_Dns::create($domain, $pair[0], $pair[1]);
            }
            $entry['reverted_at'] = time();
            $domain->setSetting(self::LOG, json_encode($log));
            self::forgetCheck($domain);
            pm_ActionLog::submit('fix_reverted', $domain->getId(), ['fix' => $entry['fix']], ['fix' => $entry['fix']]);
            return $entry;
        }
        throw new pm_Exception('Nothing to undo.');
    }

    public static function history(pm_Domain $domain)
    {
        $data = json_decode((string)$domain->getSetting(self::LOG, '[]'), true);
        return is_array($data) ? $data : [];
    }

    private static function log(pm_Domain $domain, array $entry)
    {
        $log = self::history($domain);
        array_unshift($log, $entry);
        $domain->setSetting(self::LOG, json_encode(array_slice($log, 0, self::KEEP)));
    }

    private static function who()
    {
        return pm_Session::isExist() ? pm_Session::getClient()->getLogin() : 'cli';
    }
}
