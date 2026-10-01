<?php
// Daily (scheduled in post-install.php). Folder-check jobs of past days go
// first, whatever happens after; then monitored domains that are gone or moved
// are switched off and the rule is repaired if Plesk rewrote master.cf.
pm_Context::init('email-spam-tester');
foreach (glob(pm_Context::getVarDir() . 'jobs/*', GLOB_ONLYDIR) ?: [] as $dir) {
    if (filemtime($dir) < time() - 86400) {
        foreach (scandir($dir) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                @unlink("$dir/$name");
            }
        }
        @rmdir($dir);
    }
}
try {
    Modules_EmailSpamTester_Monitor::reconcile();
} catch (Exception $e) {
    pm_Log::err('Email Spam Tester: daily reconcile failed: ' . $e->getMessage());
}
