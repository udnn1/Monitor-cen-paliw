<h1 align="center">Monitor cen paliw w Polsce</h1>

<p align="center">
  Panel do szybkiego sprawdzania aktualnych limitów cen paliw, zmian względem poprzedniego komunikatu oraz promocji na stacjach.
</p>

<p align="center">
  <img alt="PHP" src="https://img.shields.io/badge/PHP-8.x-777BB4?style=flat-square&logo=php&logoColor=white">
  <img alt="JavaScript" src="https://img.shields.io/badge/JavaScript-vanilla-F7DF1E?style=flat-square&logo=javascript&logoColor=111">
  <img alt="Chart.js" src="https://img.shields.io/badge/Chart.js-4.x-FF6384?style=flat-square&logo=chartdotjs&logoColor=white">
  <img alt="Status" src="https://img.shields.io/badge/status-active-1f8a70?style=flat-square">
</p>

## O projekcie

Aplikacja zbiera publicznie dostępne informacje o cenach paliw i promocjach, prezentując je w jednym dashboardzie. Dane pobierane są z oficjalnych źródeł (gov.pl, Monitor Polski) oraz stron sieci stacji (BP, Shell, ORLEN VITAY).

## Źródła danych

### Ceny paliw
- **gov.pl** – komunikat Ministerstwa Energii z maksymalnymi cenami detalicznymi,
- **Monitor Polski** – PDF z obwieszczeniem (fallback gdy gov.pl nie ma jeszcze artykułu na jutro).

### Promocje
- **BP** – oficjalna strona bp.com (sekcja Promocje na paliwa),
- **Shell** – model JSON z shell.pl (teaser + detale),
- **ORLEN VITAY** – vitay.pl/rabaty (strona programu lojalnościowego).

## Funkcje

- aktualne limity dla PB95, PB98 i ON,
- porównanie cen z poprzednim komunikatem,
- podgląd cen na jutro, jeśli są już dostępne,
- wykres zmian z ostatniego miesiąca (Chart.js),
- sekcja aktualnych promocji paliwowych (rabaty, daty, kategoryzacja),
- automatyczne wykrywanie najlepszej promocji (najwyższy rabat, najmniej obostrzeń),
- jasny i ciemny motyw (zapisywany w localStorage),
- ręczne odświeżanie danych z 5-minutowym cooldownem,
- automatyczne odświeżanie (auto-refresh) z throttlingiem i blokadami,
- wykrywanie nowego obwieszczenia na gov.pl i pobieranie go,
- fallback PDF z Monitora Polski gdy brak artykułu na jutro,
- CLI: odświeżanie snapshotu przez `--refresh-cache`,
- opcjonalny link do bota Telegram (t.me/CenyCPNpl).

## Stack

- PHP (bez frameworka),
- HTML i CSS (vanilla, bez bibliotek),
- vanilla JavaScript,
- Chart.js do wykresu historii cen.

## Uruchomienie lokalne

Wymagania:

- PHP 8.x,
- włączona obsługa `curl` albo dostępny binarny `curl`,
- możliwość zapisu w katalogu aplikacji.

Start serwera developerskiego:

```bash
php -S localhost:8000 index.php
```

Pierwsze pobranie snapshotu danych:

```bash
php index.php --refresh-cache
```

Po uruchomieniu wejdź na:

```text
http://localhost:8000
```

## CLI

| Flaga | Opis |
|-------|------|
| `--refresh-cache` | Wymusza odświeżenie wszystkich danych (ceny + promocje) i zapis snapshotu. |

## Cache i snapshoty

Dane robocze są zapisywane lokalnie w katalogu `.paliwa-cache/`:
- `dashboard-current.json` – główny snapshot dashboardu,
- `auto-refresh-state.json` – stan auto-odświeżania,
- `manual-refresh-cooldown.json` – cooldown ręcznego odświeżania,
- pliki `.lock` – blokady dostępu (flock).

Katalog `.paliwa-cache/` powinien pozostać poza repozytorium.
