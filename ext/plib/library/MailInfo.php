<?php
/**
 * What Plesk knows about a domain's mail.
 */
class Modules_EmailSpamTester_MailInfo
{
    /** @return string[] mailbox addresses on this domain */
    public static function mailboxes(pm_Domain $domain)
    {
        $out = pm_ApiCli::call('mail', ['--list'], pm_ApiCli::RESULT_STDOUT);
        $suffix = '@' . strtolower($domain->getName());
        $boxes = [];
        foreach (preg_split('/\r?\n/', (string)$out) as $line) {
            if (preg_match('/^Mail name\s+(\S+@\S+)$/', trim($line), $m)
                && substr(strtolower($m[1]), -strlen($suffix)) === $suffix) {
                $boxes[] = $m[1];
            }
        }
        sort($boxes);
        return $boxes;
    }

    public static function mailEnabled(pm_Domain $domain)
    {
        foreach (['site', 'domain'] as $utility) {
            try {
                $out = pm_ApiCli::call($utility, ['--info', $domain->getName()], pm_ApiCli::RESULT_STDOUT);
            } catch (Exception $e) {
                continue;
            }
            if (preg_match('/^Mail service:\s+(\w+)/mi', $out, $m)) {
                return strcasecmp($m[1], 'Enabled') === 0;
            }
        }
        return false;
    }

    /** Senders offered in the form: real mailboxes first, then a no-reply address. */
    public static function senders(pm_Domain $domain)
    {
        $boxes = self::mailboxes($domain);
        $fallback = 'noreply@' . $domain->getName();
        if (!in_array($fallback, $boxes, true)) {
            $boxes[] = $fallback;
        }
        return $boxes;
    }
}
