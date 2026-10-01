<?php

class Modules_EmailSpamTester_CustomButtons extends pm_Hook_CustomButtons
{
    public function getButtons()
    {
        $icon = pm_Context::getBaseUrl() . 'images/icon-32.png';
        $index = pm_Context::getActionUrl('index', 'index');
        $common = [
            'title' => 'Email Spam Tester',
            'description' => pm_Locale::lmsg('buttonDescription'),
            'icon' => $icon,
            'link' => $index,
        ];
        return [
            // The Mail section of a domain's page, where people look when mail goes to spam.
            array_merge($common, [
                'place' => self::PLACE_DOMAIN_PROPERTIES_DYNAMIC,
                'section' => self::SECTION_DOMAIN_PROPS_DYNAMIC_MAIL,
                'link' => pm_Context::getActionUrl('index', 'domain'),
                'contextParams' => true,
                'visibility' => [$this, 'visibleForDomain'],
            ]),
            $common + ['place' => self::PLACE_ADMIN_NAVIGATION, 'section' => self::SECTION_NAV_GENERAL],
            $common + ['place' => self::PLACE_RESELLER_NAVIGATION, 'section' => self::SECTION_NAV_GENERAL],
            $common + ['place' => self::PLACE_HOSTING_PANEL_NAVIGATION, 'section' => self::SECTION_NAV_HOSTING, 'visibility' => [$this, 'visibleForCustomer']],
            array_merge($common, ['place' => self::PLACE_ADMIN_TOOLS_AND_SETTINGS, 'section' => self::SECTION_ADMIN_TOOLS_MAIL,
                'link' => pm_Context::getActionUrl('admin', 'index'),
                'description' => 'Registration, limits for customers, constant monitoring, the server\'s mail identity']),
        ];
    }

    public function visibleForDomain($options)
    {
        $id = isset($options['dom_id']) ? $options['dom_id'] : (isset($options['site_id']) ? $options['site_id'] : null);
        if (!$id) {
            return true;
        }
        try {
            return Modules_EmailSpamTester_Access::allowed(new pm_Domain($id), 'access');
        } catch (Exception $e) {
            return false;
        }
    }

    public function visibleForCustomer($options)
    {
        foreach (Modules_EmailSpamTester_Access::domains() as $domain) {
            if (Modules_EmailSpamTester_Access::allowed($domain, 'access')) {
                return true;
            }
        }
        return false;
    }
}
