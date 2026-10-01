<?php
/**
 * The server administrator's page: registration, limits for customers,
 * constant monitoring on the server, and the server's own mail identity.
 */
class AdminController extends pm_Controller_Action
{
    const LIMITS = [
        'tests_per_account_day' => 'Tests per subscription a day',
        'placements_per_account_day' => 'Folder checks per subscription a day',
        'advice_per_account_day' => 'AI plans for monitored emails per subscription a day',
        'domains_per_account' => 'Monitored domains per subscription',
        'copies_per_domain_day' => 'Monitored emails kept per domain a day',
    ];

    public function init()
    {
        parent::init();
        if (!pm_Session::getClient()->isAdmin()) {
            throw new pm_Exception('Only the server administrator can open this page.');
        }
        $this->view->pageTitle = 'Email Spam Tester: server';
        $this->view->headLink()->appendStylesheet(pm_Context::getBaseUrl() . 'css/app.css');
        $this->view->headScript()->appendFile(pm_Context::getBaseUrl() . 'js/app.js');
    }

    public function indexAction()
    {
        $this->view->registered = Modules_EmailSpamTester_Config::isRegistered();
        $this->view->installationId = Modules_EmailSpamTester_Config::installationId();
        $this->view->registeredAt = Modules_EmailSpamTester_Config::registeredAt();
        $this->view->limitNames = self::LIMITS;
        $this->view->url = function ($action) { return pm_Context::getActionUrl('admin', $action); };
        $this->view->monitor = null;
        $this->view->limits = null;
        $this->view->serverCheck = null;
        try {
            $this->view->monitor = Modules_EmailSpamTester_Monitor::serverStatus();
            $this->view->monitored = count(Modules_EmailSpamTester_Monitor::domains());
        } catch (Exception $e) {
            $this->_status->addMessage('warning', 'Could not read the monitoring state: ' . $e->getMessage());
        }
        if ($this->view->registered) {
            try {
                $this->view->limits = (new Modules_EmailSpamTester_Api())->limits();
                $this->view->serverCheck = $this->serverCheck();
            } catch (Modules_EmailSpamTester_ApiException $e) {
                $this->_status->addMessage('error', 'Email Spam Tester did not answer: ' . $e->getMessage());
            }
        }
    }

    public function registerAction()
    {
        if ($this->getRequest()->isPost()) {
            $email = trim((string)$this->getParam('admin_email'));
            try {
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new pm_Exception('That email address does not look right.');
                }
                Modules_EmailSpamTester_Server::register($email ?: null);
                $this->_status->addMessage('info', 'The server is registered. Customers can test their mail now.');
            } catch (Exception $e) {
                $this->_status->addMessage('error', $e->getMessage());
            }
        }
        $this->back();
    }

    public function limitsAction()
    {
        if ($this->getRequest()->isPost()) {
            $values = [];
            foreach (array_keys(self::LIMITS) as $key) {
                $value = trim((string)$this->getParam($key));
                $values[$key] = $value === '' ? 0 : (int)$value;
            }
            try {
                (new Modules_EmailSpamTester_Api())->setLimits($values);
                $this->_status->addMessage('info', 'Limits saved.');
            } catch (Exception $e) {
                $this->_status->addMessage('error', $e->getMessage());
            }
        }
        $this->back();
    }

    public function monitoringAction()
    {
        if ($this->getRequest()->isPost()) {
            try {
                if ($this->getParam('do') === 'allow') {
                    $dry = Modules_EmailSpamTester_Monitor::allow(true);
                    Modules_EmailSpamTester_Monitor::allow();
                    $this->_status->addMessage('info', 'Constant monitoring is allowed. Checks passed: ' . implode(', ', $dry['steps']) . '.');
                } elseif ($this->getParam('do') === 'disable') {
                    Modules_EmailSpamTester_Monitor::disallow();
                    $this->_status->addMessage('info', 'Constant monitoring is off on the whole server; every domain is switched off.');
                }
            } catch (Exception $e) {
                $this->_status->addMessage('error', $e->getMessage());
            }
        }
        $this->back();
    }

    /** PTR, forward DNS, HELO and blocklists of the server's own IPs, as the API sees them. */
    private function serverCheck()
    {
        $host = Modules_EmailSpamTester_Server::hostname();
        if (!$host) {
            return null;
        }
        $account = substr(hash_hmac('sha256', 'plesk-server', Modules_EmailSpamTester_Config::accountSecret()), 0, 40);
        return (new Modules_EmailSpamTester_Api())->domainCheck($account, [
            'domain' => $host, 'helo' => $host, 'ips' => Modules_EmailSpamTester_Server::ips(),
        ]);
    }

    private function back()
    {
        $this->_redirect(pm_Context::getActionUrl('admin', 'index'), ['prependBase' => false]);
    }
}
