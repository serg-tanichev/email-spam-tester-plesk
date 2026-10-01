<?php
/**
 * Small formatting helpers shared by the views.
 */
class Modules_EmailSpamTester_View
{
    const CATEGORY_ORDER = ['auth', 'infra', 'spam', 'content', 'compliance', 'advisory'];
    const STATUS_ORDER = ['fail' => 0, 'error' => 1, 'warn' => 2, 'pass' => 3, 'skip' => 4];

    public static function score($value)
    {
        return $value === null ? '–' : rtrim(rtrim(number_format((float)$value, 1, '.', ''), '0'), '.');
    }

    /** Colour band of score_ours, 0 to 100. */
    public static function scoreClass($value)
    {
        if ($value === null) {
            return 'est-none';
        }
        return $value >= 80 ? 'est-good' : ($value >= 50 ? 'est-fair' : 'est-bad');
    }

    /** A timestamp or an ISO 8601 string from the API, in the server's time. */
    public static function when($value)
    {
        if ($value === null || $value === '') {
            return '';
        }
        $ts = is_numeric($value) ? (int)$value : strtotime((string)$value);
        return $ts ? date('Y-m-d H:i', $ts) : '';
    }

    public static function h($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    public static function sourceName($source)
    {
        $names = ['php' => 'Website (PHP, WordPress)', 'webmail' => 'Webmail', 'client' => 'Mail app',
                  'script' => 'Script', 'list' => 'Mailing list', 'other' => 'Other'];
        return isset($names[$source]) ? $names[$source] : $source;
    }

    public static function folderName($placement)
    {
        $names = ['inbox' => 'Inbox', 'primary' => 'Inbox (Primary)', 'promotions' => 'Promotions',
                  'updates' => 'Updates', 'social' => 'Social', 'forums' => 'Forums', 'spam' => 'Spam',
                  'not_observed' => 'Not seen', 'pending' => 'Waiting', 'unknown' => 'Unknown',
                  'multiple' => 'Several folders'];
        return isset($names[$placement]) ? $names[$placement] : ucfirst(str_replace('_', ' ', (string)$placement));
    }

    public static function categoryName($category)
    {
        $names = ['auth' => 'Authentication', 'infra' => 'Server and network', 'spam' => 'Spam filters',
                  'content' => 'Content', 'compliance' => 'Gmail and Yahoo sender rules',
                  'advisory' => 'Recommendations', 'infra_spam' => 'Server, network and spam filters'];
        return isset($names[$category]) ? $names[$category] : $category;
    }

    public static function fixTitle($fix)
    {
        $titles = ['enable_dkim' => 'Sign outgoing email with DKIM', 'dkim' => 'Publish the DKIM key',
                   'spf' => 'Allow this server in SPF', 'dmarc' => 'Publish a DMARC policy'];
        if ($fix['action'] === 'enable_dkim') {
            return $titles['enable_dkim'];
        }
        return isset($titles[$fix['id']]) ? $titles[$fix['id']] : $fix['id'];
    }

    public static function aiFinished($status)
    {
        return in_array($status, ['ready', 'fallback', 'error', 'skipped'], true);
    }

    /** Checks grouped by category, the ones that need attention first. */
    public static function grouped(array $checks)
    {
        $groups = [];
        foreach ($checks as $check) {
            $groups[$check['category']][] = $check;
        }
        foreach ($groups as &$list) {
            usort($list, function ($a, $b) {
                $sa = isset(self::STATUS_ORDER[$a['status']]) ? self::STATUS_ORDER[$a['status']] : 9;
                $sb = isset(self::STATUS_ORDER[$b['status']]) ? self::STATUS_ORDER[$b['status']] : 9;
                return $sa !== $sb ? $sa - $sb : $a['weight_ours'] <=> $b['weight_ours'];
            });
        }
        unset($list);
        $ordered = [];
        foreach (self::CATEGORY_ORDER as $category) {
            if (isset($groups[$category])) {
                $ordered[$category] = $groups[$category];
                unset($groups[$category]);
            }
        }
        return $ordered + $groups;
    }

    public static function summary(array $check)
    {
        return !empty($check['summary_localised']) ? $check['summary_localised'] : $check['summary'];
    }

    /** Placement rows from both sources, with provider names and, where no folder was seen, the refusal. */
    public static function placementRows(array $run, array $delivery = [])
    {
        $refused = [];
        foreach ($delivery as $d) {
            if ($d['recipient'] === 'placement' && in_array($d['status'], ['bounced', 'expired', 'deferred'], true)) {
                $refused[$d['provider']][] = $d;
            }
        }
        $rows = [];
        $add = function ($provider, $placement) use (&$rows, &$refused) {
            $row = ['provider' => $provider === 'gmail' ? 'Gmail' : self::providerName($provider),
                    'placement' => $placement ?: 'pending', 'refusal' => null];
            if (in_array($row['placement'], ['pending', 'not_observed'], true) && !empty($refused[$provider])) {
                $row['refusal'] = array_shift($refused[$provider]);
            }
            $rows[] = $row;
        };
        foreach (isset($run['results']) ? $run['results'] : [] as $seed) {
            $add('gmail', isset($seed['placement']) ? $seed['placement'] : 'pending');
        }
        if (!empty($run['fleet']['results'])) {
            foreach ($run['fleet']['results'] as $box) {
                $add($box['provider'], isset($box['placement']) ? $box['placement'] : 'pending');
            }
        }
        return $rows;
    }

    public static function providerName($id)
    {
        $names = [
            'aol' => 'AOL', 'freenet' => 'freenet', 'gmx' => 'GMX', 'libero' => 'Libero', 'mailcom' => 'mail.com',
            'mailru' => 'Mail.ru', 'onet' => 'Onet', 'proton' => 'Proton Mail', 'rambler' => 'Rambler',
            'tiscali' => 'Tiscali', 'webde' => 'WEB.DE', 'yahoo' => 'Yahoo', 'outlook' => 'Outlook', 'icloud' => 'iCloud',
        ];
        return isset($names[$id]) ? $names[$id] : $id;
    }

}
