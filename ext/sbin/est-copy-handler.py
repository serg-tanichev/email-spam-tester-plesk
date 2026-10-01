#!/opt/psa/bin/py3-python
"""Email Spam Tester: copy outgoing mail of monitored domains to their address.

A Plesk mail handler (queue before-remote, global, after DKIM signing). It
never changes or holds a message. Plesk hands the message on stdin and, inside
its sendmail wrapper, takes the handler's stdout as the message that goes on,
so the message is read in full and written back unchanged, as Plesk's own
handlers do, and the answer on stderr is PASS on every path.

For a domain switched on in /etc/email-spam-tester/monitor.json, a detached
process then hands the message, exactly as signed, to Postfix's listener on
127.0.0.1:10597 with the domain's permanent address as the only recipient;
Postfix delivers the copy from the same IP and HELO as the original. The mail
never waits for the copy.

Whose domain it is comes only from what the sender cannot choose: the SMTP
login, or the subscription's system user for mail from its PHP. An envelope
sender alone never decides it: anyone can write another domain there.

Deciding what the copy is worth (templates, caps, lists, auto-replies) is the
Email Spam Tester API's job. Here only: whose it is, not a loop, not too big.
"""
import json
import os
import re
import smtplib
import sys
import tempfile

LIST = "/etc/email-spam-tester/monitor.json"
INJECT = ("127.0.0.1", 10597)
MAX_BYTES = 19 * 1024 * 1024
TIMEOUT = 20
OUR_DOMAIN = "t.email-spam-tester.com"
ADDRESS_RE = re.compile(r"^m-[a-z0-9]{20}@t\.email-spam-tester\.com$")
MARKER_RE = re.compile(rb"^Subject:.*\best-[0-9a-f]{24}\b", re.I | re.M)
MEMORY = 1 << 20


def domain_of(address):
    return address.rpartition("@")[2].strip().strip(">").lower().rstrip(".") if "@" in address else ""


def owner_domain(state, sender, env):
    domains = state.get("domains") or {}
    auth = env.get("SMTP_AUTHORIZED") or ""
    if auth:
        # An authenticated client: the mailbox it logged in as is whose mail this is.
        candidate = domain_of(auth)
        return candidate if candidate in domains else None
    uid = env.get("PPP_SENDER_USER_ID")
    if uid and uid != "0":
        # A site's PHP: only a domain of this system user's own subscription.
        owned = [d for d in (state.get("uid_domains") or {}).get(uid) or [] if d in domains]
        envelope = domain_of(sender)
        if envelope in owned:
            return envelope
        return owned[0] if len(owned) == 1 else None
    return None


def read_all(stdin):
    """The whole message, in memory up to 1 MB, spooled to disk above."""
    spool = tempfile.SpooledTemporaryFile(max_size=MEMORY)
    while True:
        chunk = stdin.read(1 << 16)
        if not chunk:
            break
        spool.write(chunk)
    size = spool.tell()
    spool.seek(0)
    return spool, size


def echo(spool, stdout):
    while True:
        chunk = spool.read(1 << 16)
        if not chunk:
            break
        stdout.write(chunk)
    stdout.flush()


def target(argv, env, spool, size):
    """The monitor address for this message, or None."""
    if size > MAX_BYTES or len(argv) < 4:
        return None
    sender, recipients = argv[2], argv[3:]
    spool.seek(0)
    head = spool.read(16384)
    # Our own tests and folder checks: already with us, and a copy would loop.
    if all(domain_of(r) == OUR_DOMAIN for r in recipients) or MARKER_RE.search(head):
        return None
    with open(LIST) as fh:
        state = json.load(fh)
    domain = owner_domain(state, sender, env)
    if not domain:
        return None
    address = (state["domains"][domain] or {}).get("address", "")
    return address if ADDRESS_RE.match(address) else None


def source_of(env):
    """What only this handler knows: an SMTP login (a mail app or webmail) or a
    site's system user. Given to the API in a header on the copy alone; the
    original is never touched, and an extra header does not break DKIM."""
    if env.get("SMTP_AUTHORIZED"):
        return "client"
    uid = env.get("PPP_SENDER_USER_ID")
    return "php" if uid and uid != "0" else None


def copy_detached(sender, address, spool, source=None):
    """Send the copy from a process of its own, so Plesk is not kept waiting."""
    try:
        if os.fork():
            return
        os.setsid()
        if os.fork():
            os._exit(0)
    except OSError:
        return
    try:
        devnull = os.open(os.devnull, os.O_RDWR)
        for fd in (0, 1, 2):
            os.dup2(devnull, fd)
        spool.seek(0)
        data = spool.read()
        if source:
            data = f"X-EST-Monitor-Source: {source}\r\n".encode() + data
        with smtplib.SMTP(*INJECT, timeout=TIMEOUT, local_hostname="localhost") as smtp:
            smtp.sendmail(sender or "", [address], data)
    except Exception as exc:                                    # noqa: BLE001
        log(f"copy not sent: {type(exc).__name__}: {exc}")
    os._exit(0)


def log(message):
    try:
        import syslog
        syslog.openlog("email-spam-tester", 0, syslog.LOG_MAIL)
        syslog.syslog(syslog.LOG_WARNING, message[:300])
    except Exception:                                           # noqa: BLE001
        pass


def main():
    try:
        spool, size = read_all(sys.stdin.buffer)
    except Exception:                                           # noqa: BLE001
        # The message could not even be read: DEFER, so Plesk retries it,
        # rather than let it go on empty.
        sys.stderr.write("DEFER")
        sys.stderr.flush()
        os._exit(2)
    try:
        echo(spool, sys.stdout.buffer)
    except Exception:                                           # noqa: BLE001
        sys.stderr.write("DEFER")
        sys.stderr.flush()
        os._exit(2)
    try:
        address = target(sys.argv, os.environ, spool, size)
        if address:
            copy_detached(sys.argv[2], address, spool, source_of(os.environ))
    except Exception as exc:                                    # noqa: BLE001
        log(f"copy skipped: {type(exc).__name__}: {exc}")
    # Exactly PASS on stderr, and no interpreter shutdown in between to add to it.
    sys.stderr.write("PASS")
    sys.stderr.flush()
    os._exit(0)


if __name__ == "__main__":
    main()
