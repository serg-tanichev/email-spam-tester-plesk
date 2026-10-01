<?php
/**
 * TXT records as Plesk holds them and as the world sees them.
 *
 * The two differ when the domain's DNS is served somewhere else (registrar,
 * Cloudflare, a slave server): then an edit in Plesk changes nothing anyone
 * reads, and the honest answer is a record to copy, not a button.
 */
class Modules_EmailSpamTester_Dns
{
    public static function fqdn($host)
    {
        return rtrim(strtolower($host), '.') . '.';
    }

    public static function zone(pm_Domain $domain)
    {
        try {
            return $domain->getDnsZone();
        } catch (Exception $e) {
            return null;
        }
    }

    /** Whether Plesk edits to this zone can take effect at all. */
    public static function zoneManaged(pm_Domain $domain)
    {
        $zone = self::zone($domain);
        return $zone !== null && $zone->isEnabled() && $zone->isMaster();
    }

    /**
     * @return pm_Dns_Record[] TXT records at $host whose value starts with $prefix
     */
    public static function zoneTxt(pm_Domain $domain, $host, $prefix = '')
    {
        $zone = self::zone($domain);
        if ($zone === null) {
            return [];
        }
        $host = self::fqdn($host);
        $out = [];
        foreach ($zone->getRecordsByType(pm_Dns_Record::TYPE_TXT) as $record) {
            if (self::fqdn($record->getHost()) !== $host) {
                continue;
            }
            if ($prefix === '' || stripos(ltrim($record->getValue()), $prefix) === 0) {
                $out[] = $record;
            }
        }
        return $out;
    }



    public static function normalise($value)
    {
        return preg_replace('/\s+/', ' ', trim(trim($value), '"'));
    }

    public static function create(pm_Domain $domain, $host, $value)
    {
        $zone = self::zone($domain);
        $record = new pm_Dns_Record();
        $record->setZone($zone)->setType(pm_Dns_Record::TYPE_TXT)->setHost(self::fqdn($host))->setValue($value);
        $record->save();
        return $record->getId();
    }

    public static function update($recordId, $value)
    {
        $record = pm_Dns_Record::getById((int)$recordId);
        $record->setValue($value);
        $record->save();
    }

    public static function remove($recordId)
    {
        pm_Dns_Record::getById((int)$recordId)->remove();
    }
}
