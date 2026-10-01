<?php
/**
 * A test from the panel: reserve the address, send the email from the
 * server, and, with the folder check, one copy to each placement mailbox in
 * the background.
 */
class Modules_EmailSpamTester_Tests
{
    const PATH_MAILBOX = 'mailbox';
    const PATH_SITE = 'site';
    const JOBS = '/usr/local/psa/var/modules/email-spam-tester/jobs';
    const TEST_ADDRESS_RE = '/^test-[a-z0-9]{20}@t\.email-spam-tester\.com$/';
    const SEED_RE = '/^[a-z0-9._+-]{1,64}@(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/';
    const MAX_SEEDS = 60;

    /**
     * @param array $content from Content::standard() or Content::own()
     * @return string the test's slug
     */
    public static function start(pm_Domain $domain, $path, $from, array $content, $variant, $placement)
    {
        if (!in_array($path, [self::PATH_MAILBOX, self::PATH_SITE], true)) {
            throw new pm_Exception('Unknown way to send.');
        }
        if (!in_array($from, Modules_EmailSpamTester_MailInfo::senders($domain), true)) {
            throw new pm_Exception('Choose a sender address on this domain.');
        }
        $sysuser = null;
        if ($path === self::PATH_SITE) {
            if (!$domain->hasHosting()) {
                throw new pm_Exception('This domain has no web hosting, so there is no website to send from.');
            }
            $sysuser = Modules_EmailSpamTester_Config::subscriptionDomain($domain)->getSysUserLogin();
        }
        $api = new Modules_EmailSpamTester_Api();
        $account = Modules_EmailSpamTester_Config::account($domain);
        $reserved = $api->reserve($account, [
            'domain' => $domain->getName(), 'sender' => $from, 'variant' => $variant, 'path' => $path,
        ]);
        $address = strtolower((string)$reserved['address']);
        $slug = (string)$reserved['slug'];
        if (!preg_match(self::TEST_ADDRESS_RE, $address) || !preg_match('/^[a-z0-9]{20}$/', $slug)) {
            throw new pm_Exception('Email Spam Tester returned an unexpected address.');
        }

        $seeds = [];
        $marker = null;
        if ($placement) {
            $run = $api->createPlacement($account, $slug);
            $marker = isset($run['marker']) && preg_match('/^est-[0-9a-f]{24}$/', $run['marker']) ? $run['marker'] : null;
            $seeds = $marker ? self::seeds($run) : [];
        }

        self::writeJob($slug, $from, $sysuser, $domain, $content, $address, $marker, $seeds);
        Modules_EmailSpamTester_Mailer::send($slug);
        if ($seeds) {
            pm_ApiCli::callSbin('est-helper', ['batch', $slug], pm_ApiCli::RESULT_FULL);
        }
        return $slug;
    }

    /** Placement mailboxes from the API's answer, each checked: the only addresses besides ours we send to. */
    public static function seeds(array $run)
    {
        $rows = isset($run['results']) && is_array($run['results']) ? $run['results'] : [];
        if (isset($run['fleet']['results']) && is_array($run['fleet']['results'])) {
            foreach ($run['fleet']['results'] as $box) {
                if (!empty($box['available'])) {
                    $rows[] = $box;
                }
            }
        }
        $out = [];
        foreach ($rows as $row) {
            $address = strtolower(isset($row['address']) ? (string)$row['address'] : '');
            if (preg_match(self::SEED_RE, $address) && !in_array($address, $out, true)) {
                $out[] = $address;
            }
        }
        return array_slice($out, 0, self::MAX_SEEDS);
    }

    /** The test's messages as files: 0 to the test address, then one per placement mailbox. */
    private static function writeJob($slug, $from, $sysuser, pm_Domain $domain, array $content, $address, $marker, array $seeds)
    {
        $dir = self::JOBS . '/' . $slug;
        // Nobody but the panel (and root) reads a job: it holds the customer's
        // own email and the placement mailboxes.
        $old = umask(0077);
        if (!is_dir(self::JOBS)) {
            mkdir(self::JOBS, 0700, true);
        }
        mkdir($dir, 0700);
        $name = $domain->getName();
        $recipients = ['0' => $address];
        file_put_contents("$dir/0.eml", Modules_EmailSpamTester_Content::compose(
            $from, $address, $name, $content, 'est-' . $slug . '-0@' . $name));
        foreach (array_values($seeds) as $i => $seed) {
            $n = (string)($i + 1);
            file_put_contents("$dir/$n.eml", Modules_EmailSpamTester_Content::compose(
                $from, $seed, $name, $content, 'est-' . $slug . '-' . $n . '@' . $name, $marker));
            $recipients[$n] = $seed;
        }
        file_put_contents("$dir/job.json", json_encode(['from' => $from, 'sysuser' => $sysuser ?: '-', 'recipients' => (object)$recipients]));
        if ($seeds) {
            file_put_contents("$dir/progress.json", json_encode(['total' => count($seeds), 'sent' => 0, 'failed' => 0, 'done' => false]));
        }
        umask($old);
        self::remember($domain, $slug);
    }

    const OWN = 'tests';
    const KEEP = 500;

    /** Which tests are this domain's: a page for a slug opens only under its own domain. */
    private static function remember(pm_Domain $domain, $slug)
    {
        $slugs = self::slugs($domain);
        array_unshift($slugs, $slug);
        $domain->setSetting(self::OWN, json_encode(array_slice(array_values(array_unique($slugs)), 0, self::KEEP)));
    }

    public static function slugs(pm_Domain $domain)
    {
        $data = json_decode((string)$domain->getSetting(self::OWN, '[]'), true);
        return is_array($data) ? $data : [];
    }

    public static function owns(pm_Domain $domain, $slug)
    {
        return in_array($slug, self::slugs($domain), true);
    }

    /** At most one read of the mail log per test every 30 seconds. */
    public static function logReadDue($slug)
    {
        $marker = self::JOBS . '/' . $slug . '/.logread';
        if (!is_dir(dirname($marker)) || (is_file($marker) && filemtime($marker) > time() - 30)) {
            return false;
        }
        touch($marker);
        return true;
    }

    public static function progress($slug)
    {
        $file = self::JOBS . '/' . preg_replace('/[^a-z0-9]/', '', $slug) . '/progress.json';
        $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        return is_array($data) ? $data : null;
    }
}
