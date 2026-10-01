<?php
/**
 * What the test email says: the standard email (the same as the cPanel
 * plugin's, so reports from both panels compare), or the customer's own.
 */
class Modules_EmailSpamTester_Content
{
    const MAX_SUBJECT = 250;
    const MAX_HTML = 524288;
    const MAX_TEXT = 262144;

    /**
     * An ordinary short message, so content and spam filters judge it the way
     * they judge the account's real mail. Not a mailing: no List-Unsubscribe,
     * or the report would hold it to the bulk-sender rules. It says what it
     * is, since placement mailboxes are real inboxes people may read.
     */
    public static function standard($mailbox, $domain)
    {
        $url = 'https://' . $domain;
        $subject = "News from $domain: a short update";
        $preheader = 'A few lines on what is new with us.';
        $heading = "Hello from $domain";
        $p1 = "This is a short email from $mailbox. We are checking that our mail reaches the inbox, so it is written the way we write to customers.";
        $p2 = 'News and contact details are on our website:';
        $p3 = 'If this email reached you by mistake, just ignore it.';
        $h = function ($v) { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); };
        $html = '<!DOCTYPE html>' . "\n"
            . '<html lang="en">' . "\n"
            . '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $h($subject) . '</title></head>' . "\n"
            . '<body style="margin:0;padding:0;background:#f4f5f7;">' . "\n"
            . '<span style="display:none;max-height:0;overflow:hidden;opacity:0;">' . $h($preheader) . '</span>' . "\n"
            . '<div style="max-width:560px;margin:0 auto;padding:32px 24px;background:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.5;color:#1f2933;">' . "\n"
            . '<h1 style="font-size:22px;margin:0 0 16px;">' . $h($heading) . '</h1>' . "\n"
            . '<p style="margin:0 0 14px;">' . $h($p1) . '</p>' . "\n"
            . '<p style="margin:0 0 14px;">' . $h($p2) . ' <a href="' . $h($url) . '" style="color:#1f5fa8;">' . $h($domain) . '</a></p>' . "\n"
            . '<p style="margin:0 0 24px;">' . $h($p3) . '</p>' . "\n"
            . '<hr style="border:0;border-top:1px solid #e3e6ea;margin:0 0 12px;">' . "\n"
            . '<p style="margin:0;font-size:13px;color:#5f6b7a;">' . $h($domain) . ' &middot; <a href="mailto:' . $h($mailbox) . '" style="color:#5f6b7a;">' . $h($mailbox) . '</a></p>' . "\n"
            . "</div>\n</body>\n</html>\n";
        $text = implode("\n", [$heading, '', $p1, '', "$p2 $url", '', $p3, '', '--', "$domain · $mailbox", '']);
        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }

    /** The customer's message, as pasted: nothing added, since the point is to test exactly it. */
    public static function own($subject, $html, $text)
    {
        $subject = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', (string)$subject));
        $html = str_replace(["\r\n", "\r"], "\n", (string)$html);
        $text = str_replace(["\r\n", "\r"], "\n", (string)$text);
        if ($subject === '') {
            throw new pm_Exception('Enter a subject.');
        }
        if (mb_strlen($subject) > self::MAX_SUBJECT) {
            throw new pm_Exception('The subject is too long.');
        }
        if (trim($html) === '' && trim($text) === '') {
            throw new pm_Exception('Paste the HTML or the text of your email.');
        }
        if (strlen($html) > self::MAX_HTML) {
            throw new pm_Exception('The HTML is larger than 512 KB.');
        }
        if (strlen($text) > self::MAX_TEXT) {
            throw new pm_Exception('The text is larger than 256 KB.');
        }
        return ['subject' => $subject, 'html' => trim($html) === '' ? '' : $html,
                'text' => trim($text) === '' ? '' : $text];
    }

    /** The full message. Message-ID carries the slug, so the mail log can be searched for it. */
    public static function compose($from, $to, $domain, array $content, $messageId, $marker = null)
    {
        $subject = $content['subject'] . ($marker ? ' ' . $marker : '');
        $head = [
            'From: <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . self::header($subject),
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . $messageId . '>',
            'MIME-Version: 1.0',
        ];
        $part = function ($type, $body) {
            return "Content-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
                . quoted_printable_encode(str_replace("\n", "\r\n", $body));
        };
        if ($content['html'] !== '' && $content['text'] !== '') {
            $b = 'est-' . bin2hex(random_bytes(12));
            $head[] = 'Content-Type: multipart/alternative; boundary="' . $b . '"';
            $body = "--$b\r\n" . $part('text/plain', $content['text']) . "\r\n--$b\r\n"
                . $part('text/html', $content['html']) . "\r\n--$b--\r\n";
            return implode("\r\n", $head) . "\r\n\r\n" . $body;
        }
        $single = $content['html'] !== '' ? $part('text/html', $content['html']) : $part('text/plain', $content['text']);
        list($partHead, $partBody) = explode("\r\n\r\n", $single, 2);
        return implode("\r\n", $head) . "\r\n" . $partHead . "\r\n\r\n" . $partBody;
    }

    private static function header($value)
    {
        return preg_match('/[^\x20-\x7e]/', $value) ? mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n") : $value;
    }
}
