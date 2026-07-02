<div align="center">

# ⛽ Monitor promocji paliwowych

**Aktualne promocje paliwowe z 6 sieci, z automatycznym wykrywaniem najlepszej okazji na tańsze tankowanie.**

[![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net/)
[![Vanilla JS](https://img.shields.io/badge/Vanilla_JS-F7DF1E?style=for-the-badge&logo=javascript&logoColor=black)](https://developer.mozilla.org/docs/Web/JavaScript)
[![Chart.js](https://img.shields.io/badge/Chart.js-FF6384?style=for-the-badge&logo=chartdotjs&logoColor=white)](https://www.chartjs.org/)
[![nginx](https://img.shields.io/badge/nginx-009639?style=for-the-badge&logo=nginx&logoColor=white)](https://nginx.org/)
[![Cloudflare Tunnel](https://img.shields.io/badge/Cloudflare_Tunnel-F38020?style=for-the-badge&logo=cloudflare&logoColor=white)](https://www.cloudflare.com/products/tunnel/)
[![Supabase](https://img.shields.io/badge/Supabase-3FCF8E?style=for-the-badge&logo=supabase&logoColor=white)](https://supabase.com/)

![Single file](https://img.shields.io/badge/architektura-pojedynczy_plik-0c5b38?style=flat-square)
![No build](https://img.shields.io/badge/build-brak_(czysty_PHP)-1f8a70?style=flat-square)
![HTTP only](https://img.shields.io/badge/scraping-wy%C5%82%C4%85cznie_HTTP-35b592?style=flat-square)
![Refresh](https://img.shields.io/badge/pe%C5%82ny_refresh-~7_s-2b8a3e?style=flat-square)
![Cache first](https://img.shields.io/badge/render-cache--first-4c956c?style=flat-square)

**🌐 [paliwo.pomo.st](https://paliwo.pomo.st/)**

</div>

---

## O projekcie

Aplikacja zbiera i porządkuje aktualne promocje paliwowe z sieci **BP, Circle K,
Shell, ORLEN, MOYA i MOL**, po czym wskazuje najkorzystniejszą ofertę na benzynę,
olej napędowy i LPG. Interfejs to pojedynczy panel: baner **TOP okazji**, siatka
kafli promocji, mini-KPI (m.in. najwyższy rabat i najlepsza oferta bezwarunkowa),
szacowana oszczędność względem średnich cen krajowych oraz wykres średnich cen.

> Wcześniejsza część projektu — monitor oficjalnych limitów cen paliw
> publikowanych przez rząd — została zarchiwizowana w repo
> [`monitor-limitow-cen-paliw`](https://github.com/udnn1/monitor-limitow-cen-paliw)
> po tym, jak resort przestał publikować te dane (1 lipca 2026).

## Jak to działa

- **Scraping wyłącznie po HTTP** (`curl`) — bez przeglądarki. BP/Circle K/Shell/
  ORLEN/MOYA to parsery HTML per sieć; **MOL** korzysta z JSON API
  (`api.molpolska.pl/content`) zamiast renderowania SPA w Chromium.
- **Średnie ceny krajowe** liczone wprost z Supabase paliwomapy (kolekcja `prices`),
  używane w „Szacowanej oszczędności" (przełącznik Benzyna/Diesel) i w wykresie.
- **Auto-adaptacja** — wysokości rabatów (baza/maksimum, LPG) i warunki są
  wyliczane ze scrapowanego opisu przy każdym odświeżeniu (kuratorowana
  konfiguracja jako bezpiecznik), więc zmiana warunków przez sieć aktualizuje kafel automatycznie.
- **Ranking jednolity** dla wszystkich sieci (bez wyjątków per nazwa): kryterium =
  gwarantowany rabat na benzynę, warunek tylko jako tiebreak. Aktualnie TOP to
  **MOL −36 gr/l**.
- **Wykrywanie aktywności** ofert wg dat i **carry-over**: gdy scraper chwilowo nic
  nie zwróci, utrzymywany jest ostatni poprawny snapshot.
- **Cache-first** — wejście na stronę nigdy nie scrapuje, tylko renderuje snapshot
  z `.paliwa-cache/`; scraping dzieje się wyłącznie przy odświeżeniu.
- **Komunikaty na każdą okoliczność** — żadna sekcja nie znika po cichu (awaria
  źródła, brak cache'u, pusta historia wykresu → zawsze czytelny komunikat).

## Odświeżanie danych

Cron dobowy i przycisk działają **synchronicznie** — pełny refresh (wszystkie
promocje + średnie ceny) trwa ~7 s, bo wszystko leci po HTTP.

- **Cron** (raz na dobę, jako `www-data`):

  ```
  php /var/www/subdomains/paliwo/index.php --refresh-cache
  ```

- **Ręcznie** przyciskiem „Odśwież dane" w interfejsie (cooldown 300 s).

## Bezpieczeństwo

- SSR escapowany (`htmlspecialchars`), render kliencki przez helper `esc()` +
  walidację schematu URL (`safeUrl`), a inline JSON z `JSON_HEX_TAG` — scrapowana
  treść nie może wyjść z tagu `<script>`.
- Wywołania powłoki wyłącznie z `escapeshellarg`; adresy do pobierania nie
  pochodzą z wejścia użytkownika.

## Wymagania

- **PHP** 8.x — rozszerzenia `curl`, `mbstring`, `dom` (przeglądarka **niepotrzebna**)
- **nginx** + PHP-FPM, **cron**
- Zapisywalny katalog `.paliwa-cache/`

## Źródła

- BP — https://www.bp.com/pl_pl/poland/home/produkty_uslugi/promocje.html
- Circle K — https://www.circlek.pl/kupony
- Shell — https://www.shell.pl/stacje-shell/oferty-i-promocje.html
- ORLEN VITAY — https://vitay.pl/rabaty
- MOYA — https://moyastacja.pl/aktualnosci.html
- MOL — https://molpolska.pl/pl/kierowcy/aktualne-promocje
- Średnie ceny — https://paliwomapa.pl/

## Rozwój

AI odpowiada wyłącznie za code-review.
