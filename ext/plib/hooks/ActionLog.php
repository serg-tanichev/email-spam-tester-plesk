<?php

class Modules_EmailSpamTester_ActionLog extends pm_Hook_ActionLog
{
    public function getEvents()
    {
        return [
            'fix_applied' => pm_Locale::lmsg('eventFixApplied'),
            'fix_reverted' => pm_Locale::lmsg('eventFixReverted'),
        ];
    }
}
