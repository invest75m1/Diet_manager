# Diet Manager

Webová aplikácia na sledovanie stravy. Inšpirovaná aplikáciou Kalorické tabuľky, určená pre
malú skupinu do 10 užívateľov. Je navrhnutá najmä pre mobil a dá sa nainštalovať na plochu (PWA).

## Funkcie

- **Denný cieľ:** kalórie, bielkoviny, sacharidy, tuky, vláknina a voda.
  - Makroživiny sa zadávajú v gramoch alebo v percentách.
  - Návrh cieľa sa vypočíta podľa vzorca Mifflin-St Jeor (pohlavie, vek, výška, hmotnosť, aktivita, zámer).
  - Uchováva sa história cieľov, takže reporty hodnotia každý deň podľa cieľa, ktorý vtedy platil.
- **Tréningy:** screenshot z **Garmin Connect** prečíta AI. Spálené kalórie navýšia denný cieľ;
  bielkoviny ostávajú, sacharidy a tuky sa navýšia pomerne.
- **Zadávanie jedla:**
  - vyhľadávanie vo vlastných potravinách a v databáze **Open Food Facts**,
  - **skenovanie čiarového kódu** kamerou,
  - **fotka jedla** alebo **položky z jedálneho lístka** s voliteľným popisom; vyhodnotí ju Claude AI,
  - **textový popis** („2 vajcia, krajec chleba s maslom“), ktorý vyhodnotí Claude AI,
  - ručné zadanie, obľúbené a naposledy jedené položky, kopírovanie jedla alebo celého dňa zo včera.
  - Jedlo dňa (raňajky až večera) sa určí podľa času a dá sa zmeniť.
  - Návrh AI si pred uložením skontrolujete a upravíte. Zmena gramáže prepočíta hodnoty.
- **Vlastné potraviny a recepty:** súkromné pre každého užívateľa, prepočítané na 100 g a na porciu.
- **Hlavný prehľad dňa:**
  - kruhový ukazovateľ kalórií a pruhy makroživín,
  - farby: zelená v cieli, oranžová blízko limitu (±5 %), červená nad limitom,
  - text „zostáva X“ alebo „X nad cieľom“,
  - bielkoviny a vláknina sa hodnotia ako minimum, ostatné hodnoty ako maximum.
- **Reporty za deň, týždeň a mesiac:**
  - priemery v porovnaní s cieľom,
  - počet dní v cieli a nad cieľom,
  - graf kalórií po dňoch,
  - vývoj hmotnosti.
- **Hmotnosť:** zápis a graf vývoja.
- **Užívatelia:** prihlásenie menom a heslom, voľba „zapamätať si ma“ na 30 dní, zmena hesla.
- **Administrátor:**
  - vytvára užívateľov a nastavuje počiatočné heslo, ktoré si užívateľ povinne zmení,
  - pri zabudnutí nastaví nové heslo,
  - deaktivuje alebo zmaže účet (vrátane dát),
  - stravu užívateľov nevidí.
- **Zálohy:** denná automatická záloha databázy, uchováva sa 30 dní.
- Slovenčina, tmavý režim podľa nastavenia telefónu.

## Technológie

| Vrstva | Riešenie |
|---|---|
| Backend | PHP 8.1+ bez frameworku, PDO (MySQL/MariaDB alebo SQLite) |
| AI | [Anthropic PHP SDK](https://github.com/anthropics/anthropic-sdk-php), model `claude-opus-5-5`, štruktúrovaný JSON výstup |
| Databáza potravín | Open Food Facts API |
| Frontend | čistý JavaScript a CSS (bez build kroku), PWA, vlastné SVG grafy |
| Čiarové kódy | natívny `BarcodeDetector`, na iPhone záložne knižnica ZXing (priložená lokálne) |

## Nasadenie

Podrobný návod pre **Websupport** aj pre **cloud (Docker)** nájdete v **[docs/NASADENIE.md](docs/NASADENIE.md)**.

Stručne:

1. Stiahnite balík `diet-manager-….zip` z GitHub Actions (artefakt) alebo z Releases.
2. Obsah `web/` nahrajte do priečinka `web/` na hostingu a `app/` vedľa neho.
3. Otvorte `https://vasadomena.sk/install.php` a vyplňte databázu, API kľúč a administrátora.

## Vývoj a testy

```bash
cd app && composer install && cd ..
# lokálny server so SQLite (konfigurácia mimo repozitára)
DM_CONFIG=/tmp/dm/config.php DM_STORAGE_DIR=/tmp/dm php -S 127.0.0.1:8099 -t public
# → http://127.0.0.1:8099/install.php (zvoľte SQLite)

# integračné testy API (AI cez falošný server)
php -S 127.0.0.1:8098 tests/mock_anthropic.php &
DM_CONFIG=... DM_STORAGE_DIR=... ANTHROPIC_API_KEY=test DM_ANTHROPIC_BASE_URL=http://127.0.0.1:8098 php -S 127.0.0.1:8099 -t public &
php tests/api_test.php http://127.0.0.1:8099 admin <heslo>
```

Štruktúra:

```
public/          verejná časť (index.html, api.php, install.php, assets/, sw.js)
app/src/         PHP triedy (Auth, Db, Nutrition, AiService, OpenFoodFacts, Controllers/…)
app/schema/      SQL migrácie (mysql/, sqlite/) – spúšťajú sa automaticky
app/bin/         backup.php, restore.php
app/storage/     fotky, zálohy, logy (neverejné)
tests/           integračné testy API
```
