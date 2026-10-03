# Appointment admin — setup

Four steps, about ten minutes, all in cPanel. Do them once.

## 1. Create the database

cPanel → **MySQL® Databases**

1. **Create New Database** — name it `bookings`. cPanel prefixes it with your
   account, so it becomes something like `dantheman_bookings`.
2. **Add New User** — pick a strong password and save it somewhere.
3. **Add User To Database** → tick **ALL PRIVILEGES**.

Write down the three values it shows you: database name, username, password.
They all carry the account prefix.

The table creates itself on the first booking. There is no SQL to run.

## 2. Pick an admin password

Visit **https://danthemancan.live/make-hash.php**, enter the password you want,
and copy the long string it gives back.

## 3. Write the config

cPanel → **File Manager** → `public_html`

Copy `config.sample.php` to `config.php`, then edit it:

- `db_name`, `db_user`, `db_pass` — from step 1
- `admin_password_hash` — the string from step 2

Leave `db_host` as `localhost`.

## 4. Delete make-hash.php

**This matters.** Anyone who finds that file can generate hashes against your
site. The admin page refuses to load until it is gone, so you cannot forget.

---

## Using it

**https://danthemancan.live/admin/** — sign in with the password from step 2.

Two kinds of lead live here, in one list:

- **From the website.** Booking requests arrive on their own, carrying the
  vehicle, the day and time asked for, the trade-in and the message.
- **Added by hand.** Open **Add a lead** at the top for a walk-in, a phone
  call or a referral. Name plus a phone number or an email is all it needs;
  everything else is optional.

Each lead is tagged with which it was, so you can tell at a glance whether the
website is earning its keep.

Every lead carries a status — **new → contacted → scheduled → sold → closed** —
and a notes field for what happened on the call. Tap a phone number to dial it.
The tiles across the top are the counts and the filters at once, so "New" is
your to-do list.

## Getting told about a new booking

Open **Notifications** at the top of the admin to see which channels are on,
and press **Send a test** to prove it before relying on it. Each channel
reports back on its own line, with the reason when one fails.

All of this is optional and the channels can run together. A booking is saved
*before* any of them run, so a text that fails — or one you miss or delete —
never loses the lead. The admin is always the record.

### A text message (recommended)

Through Twilio, roughly a cent a message plus about $1.15/month for the number.

1. Sign up at **twilio.com** and buy a phone number with SMS.
2. From the console copy the **Account SID** and **Auth Token**.
3. Put them in `config.php`:

```php
'sms' => [
    'to'          => '+18647071563',   // Dan's phone
    'from'        => '+1XXXXXXXXXX',   // the Twilio number you bought
    'account_sid' => 'ACxxxxxxxx',
    'auth_token'  => 'your token',
],
```

`to` takes several numbers separated by commas if more than one person should
get them. On a trial account Twilio only sends to numbers you have verified.

Treat `auth_token` like a password. It lives only in `config.php`, which is
never committed and never uploaded by a deploy.

### A text message, free

Most carriers accept email at a gateway address and turn it into a text. No
account and no cost, but delivery is unreliable and carriers keep retiring
these, so it is a stopgap rather than something to run a business on.

```php
'notify_email' => '8647071563@vtext.com',   // Verizon
```

AT&T is `@txt.att.net`, T-Mobile `@tmomail.net`.

### Email

Same setting, pointed at an inbox instead:

```php
'notify_email' => 'danholbrook08@gmail.com',
```

### Make, Zapier or anything else

```php
'notify_url' => 'https://hook.us1.make.com/...',
```

Each booking is POSTed there as JSON. The payload carries a ready-made
`message` field — the same text the SMS channel sends — so the scenario needs
no message assembled by hand, plus `admin_url` pointing straight at the record.

## Notes

- `config.php` is never committed and never uploaded by the deploy. It lives
  only on the server. If you rebuild and redeploy, it stays put.
- Requests are rate limited to 8 per hour per visitor.
- Submissions are stored with prepared statements and rendered escaped, so a
  hostile name or message is stored and displayed as plain text.
