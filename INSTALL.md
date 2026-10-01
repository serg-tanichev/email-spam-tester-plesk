# Installing Email Spam Tester for Plesk

This guide assumes you have root on a Plesk server. Every command below is meant to be copied and pasted as it is.

## 1. What you need

- Root SSH access to the server.
- Plesk Obsidian 18.0.50 or newer on Linux, with Postfix as the mail server (the Plesk default). Tested live on Plesk Obsidian 18.0.81 on Ubuntu 24.04.
- Outgoing port 25 open, so email from the server can reach `t.email-spam-tester.com`. Many VPS providers block it by default.
- Outgoing HTTPS to `email-spam-tester.com`.

You can check both before installing. The first command should print a line that starts with `220`, the second should print `200`:

```sh
timeout 10 bash -c 'exec 3<>/dev/tcp/t.email-spam-tester.com/25 && head -1 <&3'
curl -s -o /dev/null -w '%{http_code}\n' https://email-spam-tester.com/health
```

## 2. Install

Log in over SSH as root. Download the extension and its checksum:

```sh
cd /root
curl -fsSLO https://email-spam-tester.com/plesk/latest.zip
curl -fsSLO https://email-spam-tester.com/plesk/latest.zip.sha256
```

Check that the download is intact. You should see `latest.zip: OK`. If you see `FAILED`, download again and do not continue:

```sh
sha256sum -c latest.zip.sha256
```

Install it:

```sh
plesk bin extension --install /root/latest.zip
```

The last line should be `The extension was successfully installed.`

## 3. Register the server

In Plesk, go to **Tools & Settings › Mail › Email Spam Tester** and press **Register this server**. You can leave an email address for notices about the service; it is optional.

Registration sends the server's hostname, the Plesk and extension versions, the number of subscriptions and that email. The answer is a key that stays on this server, encrypted in the extension's settings; customers never see it.

Customers find **Email Spam Tester** in **Websites & Domains › a domain › Mail**; until the server is registered it only tells them to ask you.

## 4. Choose who gets what

In **Service Plans › a plan › Permissions** (and in each subscription's own permissions) the extension adds four permissions, all on by default:

- **Email Spam Tester**: the extension itself;
- **fix DNS and mail settings**: the DKIM, SPF and DMARC buttons. DNS buttons also need the plan's own permission to manage DNS, and the DKIM switch its permission to manage mail settings;
- **folder check**: sending a copy to test mailboxes at email providers;
- **constant monitoring**: switching monitoring on for a domain.

Limits per subscription (tests, folder checks, AI plans, monitored domains, monitored emails per domain a day) are on the same **Tools & Settings › Mail › Email Spam Tester** page.

## 5. Constant monitoring (optional)

Nothing is copied until you allow it and a customer switches it on for their domain. On the extension's page in **Tools & Settings**, press **Allow constant monitoring on this server**. This adds:

- a Plesk mail handler that runs after DKIM signing for outgoing email, passes every message through unchanged and, for domains switched on, hands a copy to
- a Postfix listener on `127.0.0.1:10597` that accepts only Email Spam Tester's monitoring addresses, so the copy leaves from the same IP and HELO as the original;
- the list of monitored domains in `/etc/email-spam-tester/monitor.json`.

The same page has **Disable on the whole server**, which removes all three and switches every domain off.

To see where it stands from the command line:

```sh
/usr/local/psa/admin/sbin/modules/email-spam-tester/est-monitor status
```

`"state": "active"` means it is on; `"off"` means it was never allowed or has been removed; `"partial"` means a piece is missing (Plesk sometimes rewrites Postfix's `master.cf` on an update), and **Repair** on the same page puts it back. A daily task does the same on its own.

## 6. Upgrade

Download the new `latest.zip` and its checksum as in step 2, check it, then:

```sh
plesk bin extension --upgrade /root/latest.zip
```

The registration, limits and monitoring stay as they were; if monitoring is on, its files are brought up to the new version.

## 7. Uninstall

```sh
plesk bin extension --uninstall email-spam-tester
```

This takes monitoring out of Postfix and Plesk's mail handlers and switches every monitored domain off. It never touches DNS records, including the ones customers created with the extension's buttons.

If monitoring was on, it is worth taking it out first, so that a failure there is visible rather than only in Plesk's log:

```sh
/usr/local/psa/admin/sbin/modules/email-spam-tester/est-monitor disable
```

It prints the state at the end; `"state": "off"` means the handler, the listener and the list are gone.

## 8. If something does not work

- **"The mail server did not accept the email"** on a test: the local Postfix refused it, and the text after the colon is its own answer. Check that Postfix is running (`systemctl status postfix`).
- **A test waits and nothing arrives**: after 30 seconds the test page shows what the server's mail log says about it. A connection to port 25 that times out means your provider blocks outgoing port 25; run the first check from step 1 to confirm.
- **A fix shows a record to copy instead of a button**: the domain's DNS is served by another server (a registrar or Cloudflare); add the record there.
- **Right after a DNS fix, a test still shows the old result**: public DNS resolvers may remember that a record was missing for as long as the zone's SOA minimum TTL (three hours in Plesk's default zone template). Test again later.
