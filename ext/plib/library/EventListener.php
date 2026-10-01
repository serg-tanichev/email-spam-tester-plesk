<?php
/**
 * Plesk events: a monitored domain removed, or its subscription removed, is
 * switched off at once instead of at the next daily check.
 */
class Modules_EmailSpamTester_EventListener implements EventListener
{
    public function filterActions()
    {
        return ['domain_delete', 'site_delete', 'phys_hosting_delete', 'domain_alias_delete',
                'domain_update', 'site_update', 'phys_hosting_update', 'domain_owner_change'];
    }

    public function handleEvent($objectType, $objectId, $action, $oldValues, $newValues)
    {
        try {
            if (Modules_EmailSpamTester_Monitor::domains()) {
                Modules_EmailSpamTester_Monitor::reconcile(false);
            }
        } catch (Exception $e) {
            pm_Log::warn('Email Spam Tester: reconcile on ' . $action . ' failed: ' . $e->getMessage());
        }
    }
}

return new Modules_EmailSpamTester_EventListener();
