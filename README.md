# Email Spam Tester for Plesk

This Plesk Obsidian extension sends a test email from a hosting customer's domain through your own Postfix, signed with the domain's DKIM key and from the server's IP, so the report shows how that server's mail really arrives. The customer starts it from the domain's Mail tab and reads SPF, DKIM and DMARC, reverse DNS and HELO, blocklists, content and spam filter results, plus the folder the email landed in at 14 providers. Where the domain's DNS zone sits on your server, the missing DKIM, SPF or DMARC record is published with a button, and undone with another.

It runs on [Email Spam Tester](https://email-spam-tester.com/), which is free to use right now. What customers see, and the questions providers ask before installing, are on [email-spam-tester.com/plesk-integration/](https://email-spam-tester.com/plesk-integration/).

## Download

Get the archive from [Releases](https://github.com/serg-tanichev/email-spam-tester-plesk/releases/latest) together with its `.sha256` file, or straight from the site:

```sh
curl -fsSLO https://email-spam-tester.com/plesk/latest.zip
curl -fsSLO https://email-spam-tester.com/plesk/latest.zip.sha256
sha256sum -c latest.zip.sha256
plesk bin extension --install latest.zip
```

Then register the server under **Tools & Settings › Mail › Email Spam Tester**. [INSTALL.md](INSTALL.md) has every step, the checks to run first, upgrade, uninstall and what to do when something does not work.

It needs Plesk Obsidian 18.0.50 or newer on Linux with Postfix, outgoing port 25 and HTTPS; Plesk for Windows is not supported.

## What is in it

If you look for the scoring or for the code that decides which SPF record to write, it is not here. The server sends mail, makes copies and reads its own mail log; the judging is done by the Email Spam Tester API, and this code is mostly plumbing and pages.

```
ext/meta.xml                    extension id email-spam-tester, Plesk 18.0.50+, Linux
ext/plib/controllers/           customer pages (Test, History, Monitoring) and the administrator's page
ext/plib/library/               API client, tests, DNS fixes and undo, monitoring, permissions
ext/plib/hooks/                 the button in a domain's Mail tab, service plan permissions, action log
ext/sbin/est-helper             root helper: sends the test email and reads this test's lines of the mail log
ext/sbin/est-monitor            root helper: installs or removes the monitoring mail handler and listener
ext/sbin/est-copy-handler.py    the Plesk mail handler that copies a monitored domain's outgoing email
scripts/build_release.sh        builds the zip from an explicit file list, with SHA-256
```

To build the archive yourself: `scripts/build_release.sh` (needs `zip`), output in `dist/`.

## Privacy and support

What the extension sends, and what of it is public, is listed on the [Plesk page](https://email-spam-tester.com/plesk-integration/#how-it-works-and-what-leaves-your-server); the full policy is on the [privacy page](https://email-spam-tester.com/privacy/). Questions and bug reports: [hi@email-spam-tester.com](mailto:hi@email-spam-tester.com) or an issue here.

Built by [Serhii Tanichev](https://email-spam-tester.com/about/). MIT licensed; the license covers this code, not the Email Spam Tester service.
