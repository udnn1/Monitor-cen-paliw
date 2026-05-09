<h1 align="center">Monitor cen paliw w Polsce</h1>

<p align="center">
  Panel do szybkiego sprawdzania aktualnych limitów cen paliw, zmian względem poprzedniego komunikatu oraz wybranych promocji na stacjach.
</p>

<p align="center">
  <img alt="PHP" src="https://img.shields.io/badge/PHP-8.x-777BB4?style=flat-square&logo=php&logoColor=white">
  <img alt="JavaScript" src="https://img.shields.io/badge/JavaScript-vanilla-F7DF1E?style=flat-square&logo=javascript&logoColor=111">
  <img alt="Chart.js" src="https://img.shields.io/badge/Chart.js-4.x-FF6384?style=flat-square&logo=chartdotjs&logoColor=white">
  <img alt="Status" src="https://img.shields.io/badge/status-active-1f8a70?style=flat-square">
</p>

## O projekcie

Aplikacja zbiera publicznie dostępne informacje o cenach paliw i prezentuje je w jednym, czytelnym dashboardzie. Widok skupia się na danych potrzebnych na co dzień: aktualnych cenach, zmianach, zapowiedzi na jutro oraz krótkiej historii publikacji.

README opisuje warstwę użytkową i podstawowe uruchomienie. Szczegóły parserów, automatyki oraz konfiguracji powiadomień nie są tu dokumentowane celowo.

## Funkcje

- aktualne limity dla PB95, PB98 i ON,
- porównanie cen z poprzednim komunikatem,
- podgląd cen na jutro, jeśli są już dostępne,
- wykres zmian z ostatniego miesiąca,
- sekcja aktualnych promocji paliwowych,
- jasny i ciemny motyw panelu,
- ręczne odświeżanie danych z blokadą zbyt częstych prób,
- możliwość odświeżania snapshotu z CLI,
- opcjonalna sekcja powiadomień o zmianach.

## Stack

Projekt jest utrzymany jako lekka aplikacja bez rozbudowanego frameworka:

- PHP,
- HTML i CSS,
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

Dane robocze są zapisywane lokalnie w katalogu `.paliwa-cache/`. Ten katalog powinien pozostać poza repozytorium.

## Zakres publiczny

Repozytorium pokazuje interfejs oraz ogólną strukturę aplikacji. Dokumentacja nie publikuje wewnętrznych notatek operacyjnych, harmonogramów ani konfiguracji kanałów powiadomień.
