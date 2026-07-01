<div align="center">

# ⛽ Monitor promocji paliwowych

**Aktualne promocje paliwowe ze stacji z automatycznym wykrywaniem najlepszej okazji.**

</div>

---

## O projekcie

Aplikacja zbiera i porządkuje aktualne promocje paliwowe z sieci **BP, Circle K,
Shell i ORLEN**, a następnie wskazuje najkorzystniejszą ofertę na benzynę i olej
napędowy. Prezentacja: pojedynczy panel z siatką promocji, oznaczeniem
**TOP okazji** oraz statusem ważności każdej oferty.

> Wcześniejsza część projektu — monitor oficjalnych limitów cen paliw
> publikowanych przez rząd — została zarchiwizowana w katalogu
> [`monitor-limitow-cen-paliw/`](monitor-limitow-cen-paliw) po tym, jak
> resort przestał publikować te dane (1 lipca 2026).

## Jak to działa

- **Scraping** oficjalnych stron promocji każdej sieci (parsery per sieć,
  z awaryjnym renderowaniem przeglądarkowym tam, gdzie potrzebne).
- **Ranking** ofert względem realnego rabatu na benzynę i łatwości warunku:
  najpierw promocje bezwarunkowe, potem wysokość rabatu.
  Kolejność referencyjna: **BP** (−35 gr/l bezwarunkowo) →
  **Circle K** (−30 gr/l na miles) → **Shell** (−35 gr/l przy dowolnym produkcie) →
  **ORLEN** (−35 gr/l przy zakupie min. 5 zł).
- **Wykrywanie aktywności** ofert wg dat obowiązywania oraz **carry-over**:
  gdy scraper chwilowo nic nie zwróci, utrzymywany jest ostatni poprawny snapshot.
- **Snapshot** zapisywany w `.paliwa-cache/dashboard-current.json`.

## Odświeżanie danych

- **Cron** (raz na dobę):

  ```
  php /var/www/subdomains/paliwo/index.php --refresh-cache
  ```

- **Ręcznie** przez przycisk „Odśwież dane" w interfejsie (z limitem częstotliwości).

## Wymagania

- **PHP** 8.x — rozszerzenia `curl`, `mbstring`, `dom`
- **nginx** + PHP-FPM, **cron**
- Zapisywalny katalog `.paliwa-cache/`

## Źródła

- BP — https://www.bp.com/pl_pl/poland/home/produkty_uslugi/promocje.html
- Circle K — https://www.circlek.pl/kupony
- Shell — https://www.shell.pl/stacje-shell/oferty-i-promocje.html
- ORLEN VITAY — https://vitay.pl/rabaty

## Rozwój

Claude nie pisze kodu w tym projekcie — jego rola ogranicza się wyłącznie do code review.
