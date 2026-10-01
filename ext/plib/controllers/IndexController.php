<?php
/**
 * The customer's side: a domain's test, history and monitoring. Everything
 * shown comes from the Email Spam Tester API; this controller checks who may
 * see which domain, sends the email and applies DNS fixes the API decided.
 */
class IndexController extends pm_Controller_Action
{
    public function init()
    {
        parent::init();
        $this->view->pageTitle = 'Email Spam Tester';
        $this->view->headLink()->appendStylesheet(pm_Context::getBaseUrl() . 'css/app.css');
        $this->view->headScript()->appendFile(pm_Context::getBaseUrl() . 'js/app.js');
        $this->view->registered = Modules_EmailSpamTester_Config::isRegistered();
        $this->view->isAdmin = pm_Session::getClient()->isAdmin();
    }

    public function indexAction()
    {
        $id = $this->domainIdParam();
        if ($id) {
            $this->go('domain', ['id' => $id]);
            return;
        }
        $rows = [];
        foreach (Modules_EmailSpamTester_Access::domains() as $domain) {
            if (Modules_EmailSpamTester_Access::allowed($domain, 'access')) {
                $rows[] = $domain;
            }
        }
        if (count($rows) === 1 && pm_Session::getClient()->isClient()) {
            $this->go('domain', ['id' => $rows[0]->getId()]);
            return;
        }
        usort($rows, function ($a, $b) { return strcmp($a->getDisplayName(), $b->getDisplayName()); });
        $monitored = Modules_EmailSpamTester_Monitor::domains();
        $this->view->domains = array_map(function (pm_Domain $d) use ($monitored) {
            return ['domain' => $d, 'monitored' => isset($monitored[$d->getName()])];
        }, $rows);
    }

    public function domainAction()
    {
        $domain = Modules_EmailSpamTester_Access::domain($this->domainIdParam());
        $tab = in_array($this->getParam('tab'), ['test', 'history', 'monitoring'], true) ? $this->getParam('tab') : 'test';
        $this->view->pageTitle = 'Email Spam Tester: ' . $domain->getDisplayName();
        $this->view->domain = $domain;
        $this->view->tab = $tab;
        $this->view->urls = $this->urls($domain);
        if (!Modules_EmailSpamTester_Config::isRegistered()) {
            return;
        }
        try {
            if ($tab === 'test') {
                $this->view->check = Modules_EmailSpamTester_Fixes::cachedCheck($domain);
                $this->view->senders = Modules_EmailSpamTester_MailInfo::senders($domain);
                $this->view->mailEnabled = Modules_EmailSpamTester_MailInfo::mailEnabled($domain);
                $this->view->hasHosting = $domain->hasHosting();
                $this->view->placement = Modules_EmailSpamTester_Access::allowed($domain, 'placement');
                $this->view->canFix = Modules_EmailSpamTester_Access::allowed($domain, 'apply_fixes');
                $this->view->fixLog = Modules_EmailSpamTester_Fixes::history($domain);
            } elseif ($tab === 'history') {
                $answer = (new Modules_EmailSpamTester_Api())->history(
                    Modules_EmailSpamTester_Config::account($domain), $domain->getName(), $this->getParam('before'), 50);
                $this->view->tests = $answer['tests'];
            } else {
                $this->view->canMonitor = Modules_EmailSpamTester_Access::allowed($domain, 'monitoring');
                $this->view->server = Modules_EmailSpamTester_Monitor::serverStatus();
                $this->view->entry = Modules_EmailSpamTester_Monitor::entry($domain);
                $this->view->feed = $this->view->entry ? Modules_EmailSpamTester_Monitor::feed($domain) : null;
            }
        } catch (Modules_EmailSpamTester_ApiException $e) {
            $this->_status->addMessage('error', 'Email Spam Tester did not answer: ' . $e->getMessage());
        }
    }

    /** POST: send a test (standard or own email), or reserve an address for the customer's own send. */
    public function sendAction()
    {
        $domain = Modules_EmailSpamTester_Access::domain($this->getParam('id'));
        if (!$this->getRequest()->isPost()) {
            $this->go('domain', ['id' => $domain->getId()]);
            return;
        }
        try {
            $variant = $this->getParam('variant') === 'own' ? 'own' : 'standard';
            $from = (string)$this->getParam('from');
            $content = $variant === 'own'
                ? Modules_EmailSpamTester_Content::own($this->getParam('subject'), $this->getParam('html'), $this->getParam('text'))
                : Modules_EmailSpamTester_Content::standard($from, $domain->getName());
            $placement = $this->getParam('placement') === '1' && Modules_EmailSpamTester_Access::allowed($domain, 'placement');
            $slug = Modules_EmailSpamTester_Tests::start($domain, (string)$this->getParam('path'), $from,
                $content, $variant, $placement);
            $this->go('test', ['id' => $domain->getId(), 'slug' => $slug]);
        } catch (pm_Exception $e) {
            $this->_status->addMessage('error', $e->getMessage());
            $this->go('domain', ['id' => $domain->getId()]);
        }
    }

    /** A test: progress while it runs, then the report, folders and fixes. */
    public function testAction()
    {
        list($domain, $slug) = $this->loadTest();
        $api = new Modules_EmailSpamTester_Api();
        $this->view->pageTitle = 'Email Spam Tester: ' . $domain->getDisplayName();
        $this->view->domain = $domain;
        $this->view->slug = $slug;
        $this->view->urls = $this->urls($domain) + [
            'status' => $this->url('status', ['id' => $domain->getId(), 'slug' => $slug]),
            'report' => Modules_EmailSpamTester_Api::reportUrl($slug),
        ];
        $this->view->report = $api->report($slug);
        $this->view->placement = null;
        $this->view->delivery = [];
        $this->view->check = null;
        $this->view->canFix = Modules_EmailSpamTester_Access::allowed($domain, 'apply_fixes');
        $this->view->progress = Modules_EmailSpamTester_Tests::progress($slug);
        $history = $api->history(Modules_EmailSpamTester_Config::account($domain), $domain->getName(), null, 100);
        foreach ($history['tests'] as $item) {
            if ($item['slug'] === $slug) {
                $this->view->item = $item;
                $this->view->delivery = $item['delivery'];
                if (!empty($item['placement']['slug'])) {
                    $this->view->placement = $api->placement($item['placement']['slug']);
                }
            }
        }
        if ($this->view->report && $this->view->report['analysis_status'] === 'checks_ready') {
            $this->view->check = Modules_EmailSpamTester_Fixes::cachedCheck($domain, $slug);
            if ($this->view->placement && Modules_EmailSpamTester_Tests::logReadDue($slug)) {
                $this->view->delivery = Modules_EmailSpamTester_Mailer::reportDelivery(
                    $slug, Modules_EmailSpamTester_Config::account($domain)) ?: $this->view->delivery;
            }
        }
    }

    /** Polled by the test page: the API's status (long poll), the folder copies, what the log says. */
    public function statusAction()
    {
        list($domain, $slug) = $this->loadTest();
        $api = new Modules_EmailSpamTester_Api();
        $account = Modules_EmailSpamTester_Config::account($domain);
        $out = ['state' => 'waiting', 'progress' => Modules_EmailSpamTester_Tests::progress($slug)];
        try {
            $status = $api->status($slug, (int)$this->getParam('wait', 0) ? 15 : 0);
            if ($status) {
                $out += ['ai' => $status['ai_status'], 'done' => $status['checks_done'], 'total' => $status['checks_total']];
                $out['state'] = $status['analysis_status'];
            }
        } catch (Modules_EmailSpamTester_ApiException $e) {
            $out['error'] = $e->getMessage();
        }
        if ($out['state'] === 'waiting' && (int)$this->getParam('elapsed') >= 30
            && Modules_EmailSpamTester_Tests::logReadDue($slug)) {
            $out['delivery'] = Modules_EmailSpamTester_Mailer::reportDelivery($slug, $account);
        }
        $this->_helper->json($out);
    }

    /** Preview a fix from a fresh check; POST applies it. */
    public function fixAction()
    {
        $domain = Modules_EmailSpamTester_Access::domain($this->getParam('id'), 'apply_fixes');
        $id = (string)$this->getParam('fix');
        $report = preg_match('/^[a-z0-9]{20}$/', (string)$this->getParam('report')) ? $this->getParam('report') : null;
        $back = $report ? $this->url('test', ['id' => $domain->getId(), 'slug' => $report])
                        : $this->url('domain', ['id' => $domain->getId()]);
        if ($this->getRequest()->isPost()) {
            $allowed = $id === 'dkim' ? Modules_EmailSpamTester_Access::canChangeMailSettings($domain)
                                      : Modules_EmailSpamTester_Access::canEditDns($domain);
            try {
                if (!$allowed) {
                    throw new pm_Exception('Your hosting plan does not allow this change.');
                }
                Modules_EmailSpamTester_Fixes::apply($domain, $id, $report);
                $this->_status->addMessage('info', 'Done. Send another test to see the result; DNS changes can take a few minutes to reach receivers.');
            } catch (Exception $e) {
                $this->_status->addMessage('error', $e->getMessage());
            }
            $this->_redirect($back, ['prependBase' => false]);
            return;
        }
        $fix = null;
        foreach (($report ? Modules_EmailSpamTester_Fixes::check($domain, $report)
                          : Modules_EmailSpamTester_Fixes::cachedCheck($domain))['fixes'] as $candidate) {
            if ($candidate['id'] === $id) {
                $fix = $candidate;
            }
        }
        if (!$fix) {
            $this->_status->addMessage('warning', 'This fix no longer applies.');
            $this->_redirect($back, ['prependBase' => false]);
            return;
        }
        $this->view->pageTitle = 'Email Spam Tester: ' . $domain->getDisplayName();
        $this->view->domain = $domain;
        $this->view->fix = $fix;
        $this->view->back = $back;
        $this->view->action = $this->url('fix', array_filter(['id' => $domain->getId(), 'fix' => $id, 'report' => $report]));
    }

    public function revertAction()
    {
        $domain = Modules_EmailSpamTester_Access::domain($this->getParam('id'), 'apply_fixes');
        if ($this->getRequest()->isPost()) {
            try {
                $entryId = (string)$this->getParam('entry');
                foreach (Modules_EmailSpamTester_Fixes::history($domain) as $e) {
                    if ($e['id'] === $entryId) {
                        $allowed = $e['action'] === 'enable_dkim'
                            ? Modules_EmailSpamTester_Access::canChangeMailSettings($domain)
                            : Modules_EmailSpamTester_Access::canEditDns($domain);
                        if (!$allowed) {
                            throw new pm_Exception('Your hosting plan does not allow this change.');
                        }
                    }
                }
                Modules_EmailSpamTester_Fixes::revert($domain, $entryId);
                $this->_status->addMessage('info', 'Undone.');
            } catch (Exception $e) {
                $this->_status->addMessage('error', $e->getMessage());
            }
        }
        $this->go('domain', ['id' => $domain->getId()]);
    }

    /** POST: monitoring on or off for the domain. Off works without the permission, so a customer can always stop the copies. */
    public function monitorAction()
    {
        $want = $this->getParam('state') === 'on';
        $domain = Modules_EmailSpamTester_Access::domain($this->getParam('id'), $want ? 'monitoring' : 'access');
        if ($this->getRequest()->isPost()) {
            try {
                if ($want) {
                    Modules_EmailSpamTester_Monitor::on($domain);
                    $this->_status->addMessage('info', 'Monitoring is on. Copies of the domain\'s outgoing email now go to its private address at Email Spam Tester.');
                } else {
                    Modules_EmailSpamTester_Monitor::off($domain);
                    $this->_status->addMessage('info', 'Monitoring is off. No more copies are made.');
                }
            } catch (Exception $e) {
                $this->_status->addMessage('error', $e->getMessage());
            }
        }
        $this->go('domain', ['id' => $domain->getId(), 'tab' => 'monitoring']);
    }

    /** One monitored email's report; POST asks for its AI plan. */
    public function letterAction()
    {
        $domain = Modules_EmailSpamTester_Access::domain($this->getParam('id'));
        $entry = Modules_EmailSpamTester_Monitor::entry($domain);
        $slug = (string)$this->getParam('slug');
        if (!$entry || !preg_match('/^[a-z0-9]{16,64}$/', $slug)) {
            throw new pm_Exception('Unknown email.');
        }
        $api = new Modules_EmailSpamTester_Api();
        if ($this->getRequest()->isPost()) {
            try {
                $api->advise($slug, $entry['account']);
                $this->_status->addMessage('info', 'The plan is being written, usually within a few minutes.');
            } catch (Exception $e) {
                $this->_status->addMessage('error', $e->getMessage());
            }
            $this->go('letter', ['id' => $domain->getId(), 'slug' => $slug]);
            return;
        }
        $this->view->pageTitle = 'Email Spam Tester: ' . $domain->getDisplayName();
        $this->view->domain = $domain;
        $this->view->report = $api->letter($slug, $entry['account']);
        $this->view->slug = $slug;
        $this->view->urls = $this->urls($domain) + ['advise' => $this->url('letter', ['id' => $domain->getId(), 'slug' => $slug])];
    }

    private function loadTest()
    {
        $domain = Modules_EmailSpamTester_Access::domain($this->getParam('id'));
        $slug = (string)$this->getParam('slug');
        if (!preg_match('/^[a-z0-9]{20}$/', $slug) || !Modules_EmailSpamTester_Tests::owns($domain, $slug)) {
            throw new pm_Exception('Unknown test.');
        }
        return [$domain, $slug];
    }

    private function urls(pm_Domain $domain)
    {
        $id = $domain->getId();
        return [
            'test' => $this->url('domain', ['id' => $id]),
            'history' => $this->url('domain', ['id' => $id, 'tab' => 'history']),
            'monitoring' => $this->url('domain', ['id' => $id, 'tab' => 'monitoring']),
            'send' => $this->url('send', ['id' => $id]),
            'monitor' => $this->url('monitor', ['id' => $id]),
            'fix' => $this->url('fix', ['id' => $id]),
            'revert' => $this->url('revert', ['id' => $id]),
            'letter' => $this->url('letter', ['id' => $id]),
            'testPage' => $this->url('test', ['id' => $id]),
        ];
    }

    private function domainIdParam()
    {
        foreach (['id', 'dom_id', 'site_id'] as $name) {
            $value = (int)$this->getParam($name);
            if ($value > 0) {
                return $value;
            }
        }
        return 0;
    }

    private function url($action, array $params = [])
    {
        return pm_Context::getActionUrl('index', $action) . ($params ? '?' . http_build_query($params) : '');
    }

    private function go($action, array $params = [])
    {
        $this->_redirect($this->url($action, $params), ['prependBase' => false]);
    }
}
