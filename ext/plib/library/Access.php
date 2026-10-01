<?php
/**
 * Who may see and change what. Every controller action goes through here.
 */
class Modules_EmailSpamTester_Access
{
    /** @return pm_Domain[] domains the current user can open, main domains and add-ons alike */
    public static function domains()
    {
        $client = pm_Session::getClient();
        if ($client->isAdmin()) {
            return pm_Domain::getAllDomains();
        }
        return pm_Domain::getDomainsByClient($client);
    }

    /** The domain, if the current user may open it; throws otherwise. */
    public static function domain($id, $permission = 'access')
    {
        $id = (int)$id;
        $client = pm_Session::getClient();
        if ($id <= 0 || !$client->hasAccessToDomain($id)) {
            throw new pm_Exception('You do not have access to this domain.');
        }
        $domain = new pm_Domain($id);
        if (!self::allowed($domain, $permission)) {
            throw new pm_Exception('Your hosting plan does not allow this.');
        }
        return $domain;
    }

    public static function allowed(pm_Domain $domain, $permission)
    {
        $client = pm_Session::getClient();
        if ($client->isAdmin()) {
            return true;
        }
        return (bool)$domain->hasPermission($permission);
    }

    /** DNS changes also need Plesk's own right to manage the subscription's DNS. */
    public static function canEditDns(pm_Domain $domain)
    {
        $client = pm_Session::getClient();
        if ($client->isAdmin()) {
            return true;
        }
        return self::allowed($domain, 'apply_fixes') && $client->hasCorePermission('manage_dns', $domain);
    }

    public static function canChangeMailSettings(pm_Domain $domain)
    {
        $client = pm_Session::getClient();
        if ($client->isAdmin()) {
            return true;
        }
        return self::allowed($domain, 'apply_fixes') && $client->hasCorePermission('manage_mail_settings', $domain);
    }
}
