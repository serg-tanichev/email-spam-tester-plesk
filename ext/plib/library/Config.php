<?php
/**
 * The server's registration with Email Spam Tester.
 *
 * The key is kept encrypted in the extension's settings, which only the panel
 * reads; customers never see it and never call the API themselves. The
 * account secret pseudonymises subscriptions before they reach the API.
 */
class Modules_EmailSpamTester_Config
{
    public static function key()
    {
        return (string)pm_Settings::getDecrypted('key', '');
    }

    public static function isRegistered()
    {
        return self::key() !== '';
    }

    public static function installationId()
    {
        return (int)pm_Settings::get('installation_id', 0);
    }

    public static function registeredAt()
    {
        return pm_Settings::get('registered_at', '');
    }

    public static function accountSecret()
    {
        $secret = (string)pm_Settings::getDecrypted('account_secret', '');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(32));
            pm_Settings::setEncrypted('account_secret', $secret);
        }
        return $secret;
    }

    public static function saveRegistration($key, $installationId)
    {
        pm_Settings::setEncrypted('key', $key);
        pm_Settings::set('installation_id', (string)(int)$installationId);
        pm_Settings::set('registered_at', gmdate('Y-m-d\TH:i:s\Z'));
        self::accountSecret();
    }


    /** The subscription a domain belongs to, as the API knows it: an HMAC, never the name. */
    public static function account(pm_Domain $domain)
    {
        $main = self::subscriptionDomain($domain);
        return substr(hash_hmac('sha256', 'plesk-subscription:' . $main->getGuid(), self::accountSecret()), 0, 40);
    }

    /** The main domain of the subscription a domain belongs to. */
    public static function subscriptionDomain(pm_Domain $domain)
    {
        $webspaceId = (int)$domain->getProperty('webspace_id');
        return $webspaceId > 0 ? new pm_Domain($webspaceId) : $domain;
    }
}
