# Nasadenie Diet Manager

Aplikácia je napísaná v PHP 8.1+ a beží s MySQL/MariaDB (alebo so SQLite). Nepotrebuje Node.js
ani žiadny build krok, takže funguje na bežnom webhostingu.

* **Možnosť A – Websupport webhosting** (odporúčané, najjednoduchšie)
* **Možnosť B – cloud / VPS cez Docker**

---

## Čo budete potrebovať

1. **Webhosting s PHP 8.1 alebo novším a MySQL databázou.** Stačí bežný balík Websupport.
2. **Doménu alebo subdoménu s HTTPS.** HTTPS je nutné, inak prehliadač nepovolí kameru (fotky, čiarové kódy)
   ani inštaláciu na plochu. Websupport vydáva certifikát Let's Encrypt zadarmo.
3. **Anthropic API kľúč** na rozpoznávanie jedla z fotky a textu:
   * Zaregistrujte sa na <https://console.anthropic.com>.
   * V časti *Billing* nabite kredit (napr. 5 USD).
   * V časti *API Keys* vytvorte kľúč (začína sa `sk-ant-`).
   * **Odporúčanie:** v *Limits* si nastavte mesačný limit, napr. 8 USD.

   Jedna fotka stojí približne 1–3 centy. Pri 10 užívateľoch a bežnom používaní to vychádza
   na niekoľko eur mesačne. Bez kľúča aplikácia funguje tiež, len bez AI funkcií
   (vyhľadávanie potravín, čiarové kódy a ručné zadávanie fungujú).

---

## Možnosť A – Websupport

### 1. Stiahnite balík

Na GitHube otvorte **Actions → posledný beh → Artifacts → `diet-manager`**, alebo **Releases**,
ak bolo vydanie označené. Stiahnite súbor `diet-manager-….zip` a rozbaľte ho.

Balík obsahuje:

```
diet-manager/
├── web/        ← verejná časť (index.html, api.php, install.php, ikony…)
└── app/        ← neverejná časť (PHP kód, knižnice, fotky, zálohy)
```

> Balík si viete vytvoriť aj sami príkazom `bin/build-release.sh` (vyžaduje PHP a Composer).

### 2. Vytvorte databázu

V administrácii Websupportu (**WebAdmin → Služby → vaša doména → Databázy**):

* Kliknite na **Vytvoriť databázu** (typ MySQL/MariaDB).
* Zapíšte si **názov databázy, používateľa, heslo a server (host)**. Host býva `localhost`
  alebo adresa v tvare `db.r1.websupport.sk`; uvedený je v detaile databázy.

### 3. Nastavte PHP

V nastaveniach webu (**Webhosting → Nastavenia PHP**) zvoľte **PHP 8.1 alebo novšie** (odporúčané 8.3).

### 4. Nahrajte súbory cez FTP

Prihláste sa cez FTP (napr. FileZilla, alebo WebFTP na `webftp.websupport.sk`). V koreni domény
uvidíte priečinky `web`, `sub` a `logs`.

**Ak chcete aplikáciu na hlavnej doméne** (`https://vasadomena.sk`):

| z balíka | kam nahrať |
|---|---|
| obsah priečinka `web/` | do priečinka `web/` na serveri |
| celý priečinok `app/` | **vedľa** priečinka `web/` (do koreňa domény) |

```
/ (koreň domény na FTP)
├── app/      ← sem
├── web/      ← sem obsah web/
├── sub/
└── logs/
```

**Ak chcete subdoménu** (napr. `https://strava.vasadomena.sk`), najprv ju vytvorte vo WebAdmine.
Potom obsah `web/` nahrajte do `sub/strava/` a priečinok `app/` do `sub/app/`, teda vedľa
priečinka `strava`.

> **Ak sa nedá nahrať nič mimo `web/`:** nahrajte do `web/` celý repozitár v pôvodnej štruktúre
> (`public/` a `app/`). Súbor `.htaccess` v koreni presmeruje návštevy do `public/` a zablokuje
> prístup do `app/`.

**Práva:** priečinok `app/storage` (a jeho podpriečinky) musí byť zapisovateľný.
Na Websupporte to platí štandardne. Ak inštalátor hlási chybu, nastavte cez FTP práva `755`
alebo `775`.

### 5. Spustite inštalátor

Otvorte v prehliadači `https://vasadomena.sk/install.php`:

1. Skontrolujte, či sú všetky položky v tabuľke **OK**.
2. Vyplňte údaje o databáze z kroku 2.
3. Vložte Anthropic API kľúč (môžete ho doplniť aj neskôr).
4. Zvoľte prihlasovacie meno a heslo **administrátora**.
5. Kliknite na **Nainštalovať**.

Inštalátor vytvorí tabuľky a súbor `app/config.php`. Potom sa sám zablokuje.
Ak sa súbor nepodarí zapísať, inštalátor zobrazí jeho obsah a súbor `app/config.php` vytvoríte ručne.

### 6. Prvé kroky

1. Prihláste sa ako administrátor.
2. **Viac → Správa užívateľov → + Nový užívateľ.** Zadajte meno a počiatočné heslo. Prihlasovacie
   údaje sa dajú skopírovať a poslať. Užívateľ si heslo pri prvom prihlásení povinne zmení.
3. Každý užívateľ si nastaví **Viac → Denný cieľ**.
4. Na mobile otvorte stránku a pridajte ju na plochu:
   * **iPhone (Safari):** tlačidlo Zdieľať → *Pridať na plochu*.
   * **Android (Chrome):** menu ⋮ → *Inštalovať aplikáciu*.

   Aplikácia sa potom spúšťa ako samostatná appka bez lišty prehliadača.

### 7. Zálohy

* **Automaticky:** pri prvej požiadavke každého dňa aplikácia uloží zálohu databázy do
  `app/storage/backups/backup-RRRR-MM-DD.sql.gz` a ponechá posledných 30 dní.
* **Spoľahlivejšie cez cron:** vo WebAdmine (**Cron**) pridajte denné spúšťanie PHP skriptu:
  ```
  /cesta/k/domene/app/bin/backup.php
  ```
  Presnú fyzickú cestu nájdete v administrácii alebo v hlásení inštalátora pri položke `app/storage`.
* Websupport navyše robí vlastné denné zálohy celého hostingu.
* **Obnova zo zálohy** (ak máte SSH):
  ```
  php app/bin/restore.php app/storage/backups/backup-RRRR-MM-DD.sql.gz
  ```
  Bez SSH: záloha je štandardný SQL súbor. Rozbaľte ho (`.gz`) a importujte cez phpMyAdmin.

### Aktualizácia na novú verziu

1. Stiahnite nový balík.
2. Prepíšte obsah `web/` a priečinky `app/src`, `app/schema`, `app/bin`, `app/vendor` a súbor `app/bootstrap.php`.
3. **Neprepisujte** `app/config.php` ani `app/storage/`.

Prípadné zmeny databázy sa vykonajú automaticky pri prvom otvorení.

---

## Možnosť B – cloud alebo VPS (Docker)

Repozitár obsahuje `Dockerfile` a `docker-compose.yml` (aplikácia + MariaDB).

```bash
git clone <repozitár> diet-manager && cd diet-manager
echo "DB_PASSWORD=$(openssl rand -hex 16)" > .env
echo "ANTHROPIC_API_KEY=sk-ant-..." >> .env
docker compose up -d
```

Potom otvorte `http://server:8080/install.php`. Databáza je nastavená cez premenné prostredia,
inštalátor sa preto pýta len na administrátora. Pred aplikáciu dajte reverzný proxy s HTTPS
(napr. Caddy alebo Nginx Proxy Manager), alebo použite službu, ktorá HTTPS zabezpečí sama.

**Cloudové služby** (Render, Railway, Fly.io): nasaďte `Dockerfile` a pripojte trvalý disk (volume)
na `/data`. Najjednoduchšie je nastaviť `DM_DB_DRIVER=sqlite` a `DM_DB_PATH=/data/database.sqlite`.
Pre 10 užívateľov SQLite úplne postačuje.

| Premenná prostredia | Význam |
|---|---|
| `DM_DB_DRIVER` | `mysql` alebo `sqlite` |
| `DM_DB_HOST`, `DM_DB_PORT`, `DM_DB_NAME`, `DM_DB_USER`, `DM_DB_PASS` | pripojenie k MySQL |
| `DM_DB_PATH` | cesta k súboru SQLite |
| `ANTHROPIC_API_KEY` | kľúč pre AI |
| `DM_STORAGE_DIR` | priečinok na fotky, zálohy a logy (v Dockeri `/data`) |
| `DM_TIMEZONE` | predvolené `Europe/Bratislava` |

---

## Riešenie problémov

| Problém | Riešenie |
|---|---|
| Stránka ukazuje „Aplikácia nie je nainštalovaná“ | Otvorte `/install.php` |
| Inštalátor: „Knižnice (app/vendor) CHÝBA“ | Nahrajte priečinok `app/vendor` z balíka (nie zdrojový kód z GitHubu) |
| Kamera sa nespustí | Stránka musí bežať cez **https://**; v prehliadači povoľte prístup ku kamere |
| „AI nie je nastavená“ | Doplňte `anthropic_api_key` do `app/config.php` |
| „Neplatný Anthropic API kľúč“ / AI chyby | Skontrolujte kľúč a kredit na console.anthropic.com; podrobnosti sú v `app/storage/logs/ai.log` |
| Nahratie fotky zlyhá | Zvýšte `upload_max_filesize` na 16M v nastaveniach PHP (aplikácia fotky pred odoslaním zmenšuje, takže stačí aj 8M) |
| Iná chyba servera | Pozrite `app/storage/logs/error.log` |
| Zabudnuté heslo administrátora | Cez FTP vytvorte prázdny súbor `app/storage/reset-admin.txt` a otvorte `/install.php` – umožní nastaviť nové heslo administrátora (súbor sa potom sám zmaže) |
