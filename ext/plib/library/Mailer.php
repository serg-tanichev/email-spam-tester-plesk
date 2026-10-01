<?php
/**
 * Hands one email to the local mail server, and reads back what the mail log
 * said about a test's emails for the API to judge.
 */
class Modules_EmailSpamTester_Mailer
{
    /** The test email (message 0 of the test's job), sent now. */
    public static function send($slug)
    {
        $result = pm_ApiCli::callSbin('est-helper', ['send', $slug], pm_ApiCli::RESULT_FULL);
        if ((int)$result['code'] !== 0) {
            throw new pm_Exception('The mail server did not accept the email: '
                . trim($result['stderr'] ?: $result['stdout']));
        }
    }

    /** The mail log's lines about this test, handed to the API, which answers per recipient. */
    public static function reportDelivery($slug, $account)
    {
        try {
            $result = pm_ApiCli::callSbin('est-helper', ['log', $slug], pm_ApiCli::RESULT_FULL);
        } catch (Exception $e) {
            return null;
        }
        $data = json_decode((string)$result['stdout'], true);
        if (!is_array($data) || empty($data['lines'])) {
            return [];
        }
        $answer = (new Modules_EmailSpamTester_Api())->postDelivery($slug, $account, $data['lines']);
        return isset($answer['delivery']) ? $answer['delivery'] : [];
    }
}
