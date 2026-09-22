# Deploying from Windows PowerShell

The commands here run **on your own machine**. The server-side steps they hand
off to are in `DEPLOY.md`, and run over SSH on the CDCE web server.

`ssh` and `scp` ship with Windows 10 (1809+) and Windows 11. Check with
`ssh -V`; if it is missing, install *OpenSSH Client* under
**Settings → Apps → Optional Features**.

These commands were written for PowerShell but could not be tested in the
environment that produced them, which is Linux. Anything that misbehaves is
likely a quoting difference — say what it printed.

---

## Step W1 — Set your details once

Everything below reuses these. Run this in the PowerShell window you will keep
open:

```powershell
$Server = 'cdce.pdn.ac.lk'
$User   = 'your-ssh-username'
$Base   = 'https://cdce.pdn.ac.lk/tools/apply_examination2'
$Pkg    = "$HOME\Downloads\apply_examination2.tar.gz"
```

Check the package arrived intact:

```powershell
Test-Path $Pkg
(Get-Item $Pkg).Length / 1MB      # about 1.3
```

---

## Step W2 — Confirm you can reach the server

```powershell
Test-NetConnection $Server -Port 22    # TcpTestSucceeded must be True
ssh "$User@$Server" 'hostname; php -v | head -1'
```

If port 22 is closed, you are probably expected to reach the server through the
university VPN, or the site is managed through cPanel instead — see
**If you only have cPanel** at the bottom.

---

## Step W3 — Upload the package

```powershell
scp $Pkg "${User}@${Server}:/tmp/"
ssh "$User@$Server" 'ls -lh /tmp/apply_examination2.tar.gz'
```

---

## Step W4 — Run the server-side steps

```powershell
ssh "$User@$Server"
```

You are now on the server. Follow `DEPLOY.md` from **Step 0** through
**Step 5**: it covers finding the document root, extracting, `preflight.php`,
writing `config/config.php`, verifying against the MIS with `check_mis.php`,
and wiring up the URL.

Type `exit` to come back to PowerShell for the smoke test.

---

## Step W5 — Smoke test the live URL from Windows

The form loads:

```powershell
(Invoke-WebRequest "$Base/" -UseBasicParsing).StatusCode      # 200
```

A real eligible student gets a PDF — put a genuine NIC in `$Nic`:

```powershell
$Nic  = '931234567V'
$form = Invoke-WebRequest "$Base/" -SessionVariable sess -UseBasicParsing
$token = [regex]::Match($form.Content, 'name="csrf" value="([a-f0-9]+)"').Groups[1].Value
if (-not $token) { Write-Warning 'No CSRF token found - is the new page actually live?' }

$out = "$env:TEMP\live.pdf"
$resp = Invoke-WebRequest "$Base/" -Method Post -WebSession $sess -UseBasicParsing `
        -Body @{ csrf = $token; nic = $Nic } -OutFile $out -PassThru

$resp.Headers['Content-Type']           # application/pdf
$resp.Headers['Content-Disposition']    # attachment; filename="BA-100-Level-2026-...pdf"
Get-Item $out | Select-Object Name, Length
Invoke-Item $out                        # opens it - check all four particulars
```

If `Content-Type` comes back as `text/html`, the tool refused the request. Read
why:

```powershell
$html = Invoke-WebRequest "$Base/" -Method Post -WebSession $sess -UseBasicParsing `
        -Body @{ csrf = $token; nic = $Nic }
[regex]::Match($html.Content, '<p class="error"[^>]*>([^<]*)').Groups[1].Value
```

Then the same student in the other NIC format — both must return the same
application:

```powershell
$form2 = Invoke-WebRequest "$Base/" -SessionVariable s2 -UseBasicParsing
$t2 = [regex]::Match($form2.Content, 'name="csrf" value="([a-f0-9]+)"').Groups[1].Value
Invoke-WebRequest "$Base/" -Method Post -WebSession $s2 -UseBasicParsing `
    -Body @{ csrf = $t2; nic = '199312304567' } -OutFile "$env:TEMP\live2.pdf" -PassThru |
    Select-Object -ExpandProperty Headers | ForEach-Object { $_['Content-Disposition'] }
```

---

## Step W6 — The checks that must FAIL

Credentials and the audit log must not be fetchable. **Both must report 403 or
404.** Anything else means Step 5 is not protecting them and the tool must come
straight back down.

```powershell
foreach ($p in 'config/config.php', 'var/download.log', 'src/MisRepository.php') {
    try {
        $r = Invoke-WebRequest "$Base/$p" -UseBasicParsing -ErrorAction Stop
        Write-Host ("{0,-28} {1}  <-- EXPOSED" -f $p, $r.StatusCode) -ForegroundColor Red
    } catch {
        $code = $_.Exception.Response.StatusCode.value__
        $colour = if ($code -in 403, 404) { 'Green' } else { 'Red' }
        Write-Host ("{0,-28} {1}" -f $p, $code) -ForegroundColor $colour
    }
}
```

---

## If you only have cPanel / FTP

No SSH is needed, but `tools/preflight.php` and `tools/check_mis.php` then have
to run from cPanel's **Terminal**, or via *Cron Jobs* set to run once.

1. **File Manager → Upload** `apply_examination2.tar.gz` into the folder that
   serves `/tools/`, then use **Extract**.
2. Rename the existing `apply_examination2` folder first, so you can roll back.
3. The bundled `.htaccess` files handle the protection (`DEPLOY.md` Step 5b).
   cPanel enables `mod_rewrite` by default.
4. Create `config/config.php` with **File Manager → +File**, paste in
   `config/config.example.php`, and set its permissions to `640`.
5. Create a `var/` folder with permissions `750`.
6. Run Step W5 and **Step W6** from PowerShell exactly as above — those test
   the live URL and do not care how the files got there.
