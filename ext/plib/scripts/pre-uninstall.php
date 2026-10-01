<?php
// Takes monitoring out of Postfix and Plesk's mail handlers and switches every
// domain off at the API. DNS records customers created are never touched.
pm_Context::init('email-spam-tester');
try {
    Modules_EmailSpamTester_Monitor::disallow();
} catch (Exception $e) {
    pm_Log::err('Email Spam Tester: removing monitoring failed, run '
        . '/usr/local/psa/admin/sbin/modules/email-spam-tester/est-monitor disable by hand: ' . $e->getMessage());
}
$taskId = pm_Settings::get('reconcile_task_id');
if ($taskId) {
    $task = pm_Scheduler::getInstance()->getTaskById($taskId);
    if ($task) {
        pm_Scheduler::getInstance()->removeTask($task);
    }
}
