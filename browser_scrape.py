import os, re, json

os.environ.setdefault("HOME", "/var/lib/paliwo-browser")

CHROME = "/opt/pomost-chromium/chrome"
MOL_URL = "https://molpolska.pl/pl/kierowcy/aktualne-promocje"
PM_URL = "https://paliwomapa.pl/"
EMOJI = re.compile("[\U0001F000-\U0001FAFF☀-➿⬀-⯿️‍←-⇿⌀-⏿]")


def clean(text):
    text = EMOJI.sub(" ", text)
    text = text.replace(" ", " ")
    return re.sub(r"\s+", " ", text).strip()


def to_iso(value):
    m = re.match(r"(\d{4})\.(\d{2})\.(\d{2})", value.strip())
    return "%s-%s-%s" % (m.group(1), m.group(2), m.group(3)) if m else None


def mol_detail(page, url, title):
    try:
        page.goto(url, wait_until="domcontentloaded", timeout=40000)
        try:
            page.wait_for_function(
                "() => { const s=['main','article','.content-container','.article-content'];"
                " return s.some(x => { const e=document.querySelector(x); return e && (e.innerText||'').length>200; }); }",
                timeout=12000,
            )
        except Exception:
            page.wait_for_timeout(1500)
        txt = page.evaluate(
            "() => { const sels = ['main', 'article', '.content-container', '.article-content'];"
            " for (const s of sels) { const el = document.querySelector(s);"
            " if (el && (el.innerText || '').length > 200) return el.innerText; }"
            " return document.body.innerText; }"
        )
    except Exception:
        return None

    if not txt:
        return None

    idx = txt.find("Najniższa cena")
    if idx > 0:
        txt = txt[:idx]

    keep = []
    for ln in txt.split("\n"):
        s = ln.strip()
        if not s:
            continue
        low = s.lower()
        if "aktualne promocje" in low or low.startswith("okres obowiązywania") or s == title:
            continue
        keep.append(s)

    desc = clean(" ".join(keep))
    return desc or None


def scrape_mol(browser):
    out = []
    ctx = browser.new_context(locale="pl-PL")
    page = ctx.new_page()
    try:
        page.goto(MOL_URL, wait_until="domcontentloaded", timeout=40000)
        try:
            page.wait_for_selector(".promotions-container .row > *", timeout=12000)
        except Exception:
            pass
        page.wait_for_timeout(400)
        tiles = page.evaluate(
            "() => { const c = document.querySelector('.promotions-container .row');"
            " if (!c) return [];"
            " return [...c.children].map(t => { const a = t.querySelector('a[href]');"
            " return { text: (t.innerText || '').trim(), href: a ? a.getAttribute('href') : null }; }); }"
        )
    except Exception:
        ctx.close()
        return out

    parsed = []
    for raw in tiles:
        if not isinstance(raw, dict):
            continue
        text = raw.get("text") or ""
        if not text:
            continue
        lines = [ln.strip() for ln in text.split("\n") if ln.strip()]
        if not lines:
            continue
        title = clean(lines[0])
        full = clean(" ".join(lines))
        href = raw.get("href") or ""
        url = None
        if href:
            if href.startswith("http"):
                url = href
            elif href.startswith("/"):
                url = "https://molpolska.pl" + href
            else:
                url = "https://molpolska.pl/" + href
        from_iso = to_iso_value = None
        span = re.search(r"(\d{4}\.\d{2}\.\d{2})\.?\s*-\s*(\d{4}\.\d{2}\.\d{2})", full)
        if span:
            from_iso = to_iso(span.group(1))
            to_iso_value = to_iso(span.group(2))
        else:
            start = re.search(r"rozpocz\w*\s*promocji:?\s*(\d{4}\.\d{2}\.\d{2})", full)
            if start:
                from_iso = to_iso(start.group(1))
        parsed.append({"title": title, "text": full, "url": url, "fromIso": from_iso, "toIso": to_iso_value})

    for entry in parsed:
        is_fuel = re.search(r"\d{1,3}\s*gr\s*/\s*l", entry["text"], re.I) is not None
        is_niche = re.search(r"magenta|wybranych\s+stacj", entry["text"], re.I) is not None
        if entry["url"] and is_fuel and not is_niche:
            detail = mol_detail(page, entry["url"], entry["title"])
            if detail:
                entry["text"] = detail
        out.append(entry)

    ctx.close()
    return out


def pm_price(block, label):
    m = re.search(label + r"\s*[:|]?\s*([0-9]+[.,][0-9]{2})", block, re.I)
    return float(m.group(1).replace(",", ".")) if m else None


def scrape_pm(browser):
    ctx = browser.new_context(
        geolocation={"latitude": 52.23, "longitude": 21.01},
        permissions=["geolocation"],
        locale="pl-PL",
    )
    page = ctx.new_page()
    block = None
    try:
        page.goto(PM_URL, wait_until="domcontentloaded", timeout=60000)
        for _ in range(16):
            page.wait_for_timeout(1200)
            if "moment" not in page.title().lower():
                break
        for _ in range(18):
            page.wait_for_timeout(1200)
            body = page.inner_text("body")
            m = re.search(r"ŚREDNIE CENY PALIW W POLSCE[\s\S]{0,300}?aktualizacja:\s*\d{1,2}\.\d{1,2}\.\d{4}", body)
            if m and len(re.findall(r"[0-9]+[.,][0-9]{2}", m.group(0))) >= 3:
                block = m.group(0)
                break
    except Exception:
        ctx.close()
        return None

    ctx.close()

    if not block:
        return None

    benzyna = pm_price(block, "PB\\s*95")
    diesel = pm_price(block, r"\bON")

    def ok(v):
        return isinstance(v, float) and 4 <= v <= 12

    if not (ok(benzyna) and ok(diesel)):
        return None

    stations = None
    ms = re.search(r"\((\d+)\s*stacji\)", block)
    if ms:
        stations = int(ms.group(1))

    date_iso = None
    md = re.search(r"aktualizacja:\s*(\d{1,2})\.(\d{1,2})\.(\d{4})", block)
    if md:
        date_iso = "%04d-%02d-%02d" % (int(md.group(3)), int(md.group(2)), int(md.group(1)))

    return {
        "benzyna": benzyna,
        "pb98": pm_price(block, "PB\\s*98"),
        "diesel": diesel,
        "lpg": pm_price(block, "LPG"),
        "stations": stations,
        "date": date_iso,
    }


def main():
    try:
        from patchright.sync_api import sync_playwright
    except Exception:
        print(json.dumps({"mol": [], "averages": None}))
        return

    out = {"mol": [], "averages": None}
    try:
        with sync_playwright() as p:
            browser = p.chromium.launch(
                headless=False,
                executable_path=CHROME,
                args=["--no-sandbox", "--disable-dev-shm-usage", "--window-size=1400,1000"],
            )
            try:
                out["mol"] = scrape_mol(browser)
            except Exception:
                out["mol"] = []
            try:
                out["averages"] = scrape_pm(browser)
            except Exception:
                out["averages"] = None
            browser.close()
    except Exception:
        pass

    print(json.dumps(out, ensure_ascii=False))


if __name__ == "__main__":
    main()
