<?php
// Runs on install and on every upgrade.
pm_Context::init('email-spam-tester');

$jobs = pm_Context::getVarDir() . 'jobs';
if (!is_dir($jobs)) {
    mkdir($jobs, 0700, true);
}
chmod($jobs, 0700);

// A daily check: domains that were removed or moved are switched off, and the
// monitoring rule is put back if Plesk rewrote Postfix's master.cf.
$scheduler = pm_Scheduler::getInstance();
$taskId = pm_Settings::get('reconcile_task_id');
$task = $taskId ? $scheduler->getTaskById($taskId) : null;
if (!$task) {
    $task = new pm_Scheduler_Task();
    $task->setSchedule(['minute' => (string)rand(0, 59), 'hour' => (string)rand(1, 5),
                        'dom' => '*', 'month' => '*', 'dow' => '*']);
    $task->setCmd('reconcile.php');
    $scheduler->putTask($task);
    pm_Settings::set('reconcile_task_id', $task->getId());
}

// An upgrade brings the rule's files up to this version when monitoring is on.
try {
    Modules_EmailSpamTester_Monitor::reconcile();
} catch (Exception $e) {
    pm_Log::warn('Email Spam Tester: monitoring refresh after install failed: ' . $e->getMessage());
}
