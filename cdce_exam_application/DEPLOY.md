# Deploying to https://cdce.pdn.ac.lk/tools/apply_examination2/

Everything here is run on the CDCE web server over SSH. Dependencies are
bundled, so the server needs **no Composer and no internet access**.

Work through it in order. Stop at the first `FAIL` — each step checks the one
before it.

---

## Step 0 — Find out what the server looks like

Run this first. The output decides how the rest is wired up.

```bash
php -v
php -m | grep -E '^(pdo|pdo_mysql|mbstring|iconv|zlib)$'

# Where does the site live, and where does /tools/ actually point?
apachectl -S 2>/dev/null | grep -iE 'port 443|namevhost|DocumentRoot'
grep -riE 'DocumentRoot|Alias' /etc/apache2/sites-enabled/ 2>/dev/null

# Is the existing tool a directory under the document root?
ls -la /var/www/html/tools/ 2>/dev/null

# Needed only for the in-document-root layout in Step 5b
apachectl -M 2>/dev/null | grep rewrite
```

If the server runs nginx rather than Apache, say so — the `.htaccess` files in
this package do nothing under nginx and the equivalent goes in the server
block.

---

## Step 1 — Upload and extract

From your own machine:

```bash
scp apply_examination2.tar.gz <user>@cdce.pdn.ac.lk:/tmp/
```

On the server, extract **outside the document root** if you can. That is the
safer layout, because only `public/` ever becomes reachable:

```bash
sudo mkdir -p /opt/cdce
sudo tar xzf /tmp/apply_examination2.tar.gz -C /opt/cdce
sudo chown -R root:www-data /opt/cdce/apply_examination2
cd /opt/cdce/apply_examination2
```

If you have no root and must put it under the document root, extract to
`/var/www/html/tools/` instead and follow Step 5b. Do not delete the existing
`apply_examination2` directory — rename it, so you can roll back:

```bash
sudo mv /var/www/html/tools/apply_examination2 /var/www/html/tools/apply_examination2.old-$(date +%F)
```

---

## Step 2 — Check the server can run it

```bash
php tools/preflight.php
```

Every line must read `OK`, except `config/config.php exists`, which is a
`WARN` until Step 3. If `pdo_mysql` is missing, install it
(`sudo apt install php-mysql` or `yum install php-mysqlnd`) and reload PHP-FPM
or Apache.

---

## Step 3 — Point it at the MIS

```bash
cp config/config.example.php config/config.php
sudo chown root:www-data config/config.php
sudo chmod 640 config/config.php      # the MIS password lives in this file
nano config/config.php
```

Three things must be set to real values:

1. `mis.dsn`, `mis.username`, `mis.password` — use a MIS account with
   **SELECT only**. This tool never writes to the MIS.
2. `mis.table` and `mis.columns` — the real student table and the four
   columns. The file ships with guesses (`student`, `reg_no`, `nic_no`, ...).
3. `mis.eligibility.sql` — **the important one.** It decides who is entitled
   to a 100 Level repeat form. Too permissive and any student in the MIS can
   download one.

### Finding the real table and column names

Two read-only scripts ship with the package. Neither writes anything, and
neither returns a student row - only schema and counts, so their output is safe
to paste into a ticket or a chat.

```bash
mysql -h 10.40.129.2 -u <user> -p -D cdcesys --table < tools/discover_mis.sql
```

That lists the databases, the biggest tables, and every column that looks like
a NIC, a registration number, a name, or something the eligibility rule could
use. Fill the six names it gives you into the top of the second script, along
with the eligibility clause you intend to use, then:

```bash
mysql -h 10.40.129.2 -u <user> -p -D cdcesys --table < tools/check_data_quality.sql
```

That one answers whether the tool will actually work on this data:

| Section | What a bad answer means |
|---|---|
| 1. candidates selected | A count near zero or near the whole table means the eligibility rule is wrong |
| 2. NIC formats | `UNRECOGNISED` or `MISSING` rows are students who can never download a form |
| 3. incomplete records | Each one is a student the office must fix before they can apply |
| 4. duplicate NICs | Each blocks that student until the MIS is corrected |
| 5. 2000s NIC collisions | Above zero confirms the old-format guard in `src/Nic.php` is load-bearing |
| 6. eligibility columns | Prints ready-to-run queries showing what values those columns really hold |

Section 6 matters most: run what it prints and set the constants in
`config.php` to values that exist in the data, rather than assumed ones.

### The MIS account

Create one that can only read the student table:

```sql
CREATE USER 'cdce_readonly'@'<web-server-ip>' IDENTIFIED BY '<strong-password>';
GRANT SELECT ON cdcesys.<student-table> TO 'cdce_readonly'@'<web-server-ip>';
FLUSH PRIVILEGES;
```

Confirm it cannot write - this must be refused:

```bash
mysql -h 10.40.129.2 -u cdce_readonly -p -D cdcesys \
      -e "UPDATE <student-table> SET full_name='x' WHERE 1=0;"
# ERROR 1142 (42000): UPDATE command denied to user 'cdce_readonly'...
```

If the MIS database credentials are not to hand, the MIS web application at
`http://10.40.129.2/cdcesys/mis_1/` has them in its own config file - but
create a separate read-only account rather than reusing the MIS application's,
which will have write access.

---

## Step 4 — Verify the config against the live MIS

```bash
php tools/check_mis.php
```

This connects, resolves the table, all four columns and the eligibility clause,
without reading anybody's record. Then test one real student — pick a candidate
you know is eligible:

```bash
php tools/check_mis.php 931234567V
```

It writes the generated application to `var/`. **Open that PDF and check all
four particulars before going further.** Then test the same student using the
other NIC format (the 12-digit form if you used the 9-digit one, or the
reverse); both must produce the same application.

```bash
sudo mkdir -p var && sudo chown www-data:www-data var && sudo chmod 750 var
```

`var/` holds the rate-limit counters and the audit log, so the web user must be
able to write to it.

---

## Step 5 — Wire up the URL

### 5a. Package outside the document root (preferred)

Add to the HTTPS vhost, then reload:

```apache
Alias /tools/apply_examination2 /opt/cdce/apply_examination2/public

<Directory /opt/cdce/apply_examination2/public>
    Options -Indexes
    AllowOverride None
    Require all granted
</Directory>
```

```bash
sudo apachectl configtest && sudo systemctl reload apache2
```

Nothing but `public/` is inside the document root, so `config/config.php` and
`var/` cannot be fetched over the web at all.

### 5b. Package inside the document root (fallback)

The bundled `.htaccess` files already deny `config/`, `src/`, `tools/`,
`tests/`, `vendor/`, `templates/` and `var/`, and rewrite requests into
`public/` so the URL keeps its present shape. They need
`AllowOverride All` on that directory and `mod_rewrite` enabled:

```bash
sudo a2enmod rewrite && sudo systemctl reload apache2
```

**This layout is only as safe as those `.htaccess` files**, so confirm Step 6's
last two checks return 403 before you announce the tool.

---

## Step 6 — Smoke test the live URL

```bash
BASE=https://cdce.pdn.ac.lk/tools/apply_examination2

# the form loads
curl -sS -o /dev/null -w 'form: %{http_code}\n' $BASE/

# a real eligible student gets a PDF
COOKIE=$(mktemp)
TOKEN=$(curl -sS -c $COOKIE $BASE/ | grep -o 'name="csrf" value="[a-f0-9]*"' | sed 's/.*value="//;s/"//')
curl -sS -b $COOKIE -c $COOKIE -X POST $BASE/ \
     -d "csrf=$TOKEN" -d "nic=931234567V" \
     -o /tmp/live.pdf -D /tmp/live.headers -w 'download: %{http_code}\n'
grep -i 'content-type\|content-disposition' /tmp/live.headers
file /tmp/live.pdf        # must say: PDF document, 2 page(s)

# credentials and the audit log must NOT be fetchable - both must be 403 or 404
curl -sS -o /dev/null -w 'config:  %{http_code}\n' $BASE/config/config.php
curl -sS -o /dev/null -w 'var:     %{http_code}\n' $BASE/var/download.log
```

Open `/tmp/live.pdf` and confirm the four particulars print in the right boxes.

---

## Rolling back

Nothing in this tool writes to the MIS, so a rollback is just restoring the URL:

```bash
# 5a: comment out the Alias block, then
sudo apachectl configtest && sudo systemctl reload apache2

# 5b:
sudo rm -rf /var/www/html/tools/apply_examination2
sudo mv /var/www/html/tools/apply_examination2.old-<date> \
        /var/www/html/tools/apply_examination2
```

---

## After it is live

- Watch `var/download.log` for the first day. Each line is a timestamp, an
  outcome (`issued`, `not-found`, `incomplete`, `ambiguous`, `mis-error`) and a
  masked NIC.
- A run of `incomplete` means the MIS is missing names for real candidates —
  those students cannot get a form until the office fills them in.
- A run of `ambiguous` means duplicate rows in the MIS under one NIC.
- Many `not-found` from one client is someone guessing; the per-client cap
  (`rate_limit` in config) slows that down.
