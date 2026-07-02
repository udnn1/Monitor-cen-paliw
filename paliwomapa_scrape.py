import os, re, json

os.environ.setdefault("HOME", "/var/lib/paliwo-browser")

URL = "https://paliwomapa.pl/"
CHROME = "/opt/pomost-chromium/chrome"


def price(block, label):
    m = re.search(label + r"\s*[:|]?\s*([0-9]+[.,][0-9]{2})", block, re.I)
    if not m:
        return None
    return float(m.group(1).replace(",", "."))


def main():
    try:
        from patchright.sync_api import sync_playwright
    except Exception:
        print("{}")
        return

    result = {}
    try:
        with sync_playwright() as p:
            browser = p.chromium.launch(
                headless=False,
                executable_path=CHROME,
                args=["--no-sandbox", "--disable-dev-shm-usage", "--window-size=1400,1000"],
            )
            ctx = browser.new_context(
                geolocation={"latitude": 52.23, "longitude": 21.01},
                permissions=["geolocation"],
                locale="pl-PL",
            )

            page = ctx.new_page()
            page.goto(URL, wait_until="domcontentloaded", timeout=60000)

            for _ in range(20):
                page.wait_for_timeout(1500)
                if "moment" not in page.title().lower():
                    break

            block = None
            for _ in range(24):
                page.wait_for_timeout(1200)
                body = page.inner_text("body")
                m = re.search(r"ŚREDNIE CENY PALIW W POLSCE[\s\S]{0,300}?aktualizacja:\s*\d{1,2}\.\d{1,2}\.\d{4}", body)
                if m and len(re.findall(r"[0-9]+[.,][0-9]{2}", m.group(0))) >= 3:
                    block = m.group(0)
                    break

            ctx.close()
            browser.close()
    except Exception:
        print("{}")
        return

    if not block:
        print("{}")
        return

    benzyna = price(block, "PB\\s*95")
    pb98 = price(block, "PB\\s*98")
    diesel = price(block, r"\bON")
    lpg = price(block, "LPG")

    def ok(v, lo, hi):
        return isinstance(v, float) and lo <= v <= hi

    if not (ok(benzyna, 4, 12) and ok(diesel, 4, 12)):
        print("{}")
        return

    stations = None
    ms = re.search(r"\((\d+)\s*stacji\)", block)
    if ms:
        stations = int(ms.group(1))

    date_iso = None
    md = re.search(r"aktualizacja:\s*(\d{1,2})\.(\d{1,2})\.(\d{4})", block)
    if md:
        date_iso = "%04d-%02d-%02d" % (int(md.group(3)), int(md.group(2)), int(md.group(1)))

    result = {
        "benzyna": benzyna,
        "pb98": pb98,
        "diesel": diesel,
        "lpg": lpg,
        "stations": stations,
        "date": date_iso,
    }
    print(json.dumps(result, ensure_ascii=False))


if __name__ == "__main__":
    main()
