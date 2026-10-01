<?php

class Modules_EmailSpamTester_Permissions extends pm_Hook_Permissions
{
    public function getPermissions()
    {
        // All on by default (decision of 28.09): hosting customers see the
        // extension the day it is installed; the provider can switch any of
        // these off per service plan.
        $perm = function ($key) {
            return [
                'default' => true,
                'place' => self::PLACE_ADDITIONAL,
                'name' => pm_Locale::lmsg('perm_' . $key),
                'description' => pm_Locale::lmsg('perm_' . $key . 'Description'),
            ];
        };
        return [
            'access' => $perm('access'),
            'apply_fixes' => $perm('apply_fixes'),
            'placement' => $perm('placement'),
            'monitoring' => $perm('monitoring'),
        ];
    }
}
