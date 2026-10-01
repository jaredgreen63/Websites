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

Every booking request lands here with the vehicle, the day and time they
asked for, their trade-in and their notes. Tap the phone number to call.

Each one carries a status — **new → contacted → scheduled → sold → closed** —
and a notes field for what happened on the call. The tabs across the top
filter by status, so "New" is your to-do list.

## Text message notifications

Set `notify_url` in `config.php` to a webhook (Make.com or Zapier) and every
new request is POSTed to it as JSON:

```json
{
  "id": 42,
  "name": "Marcus Webb",
  "phone": "8645550199",
  "vehicle": "2026 Chevrolet Tahoe Premier",
  "preferred": "Thu 10/2 Afternoon",
  "admin_url": "https://danthemancan.live/admin/?id=42"
}
```

Point that at an SMS action and the text arrives with a link straight to the
record. The request is saved first either way — a text you miss or delete
never loses the lead.

## Notes

- `config.php` is never committed and never uploaded by the deploy. It lives
  only on the server. If you rebuild and redeploy, it stays put.
- Requests are rate limited to 8 per hour per visitor.
- Submissions are stored with prepared statements and rendered escaped, so a
  hostile name or message is stored and displayed as plain text.
