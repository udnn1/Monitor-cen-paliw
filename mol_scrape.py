import os, sys, json, re

os.environ.setdefault("HOME", "/var/lib/paliwo-browser")

URL = "https://molpolska.pl/pl/kierowcy/aktualne-promocje"
CHROME = "/opt/pomost-chromium/chrome"
EMOJI = re.compile(
    "[\U0001F000-\U0001FAFF☀-➿⬀-⯿️‍←-⇿⌀-⏿]"
)


def to_iso(value):
    m = re.match(r"(\d{4})\.(\d{2})\.(\d{2})", value.strip())
    return "%s-%s-%s" % (m.group(1), m.group(2), m.group(3)) if m else None


def clean(text):
    text = EMOJI.sub(" ", text)
    text = text.replace(" ", " ")
    return re.sub(r"\s+", " ", text).strip()


def main():
    try:
        from patchright.sync_api import sync_playwright
    except Exception:
        print("[]")
        return

    out = []
    try:
        with sync_playwright() as p:
            browser = p.chromium.launch(
                headless=True,
                executable_path=CHROME,
                args=["--no-sandbox", "--disable-dev-shm-usage", "--disable-gpu"],
            )
            page = browser.new_page()
            page.goto(URL, wait_until="networkidle", timeout=40000)
            page.wait_for_timeout(2500)
            tiles = page.evaluate(
                "() => { const c = document.querySelector('.promotions-container .row');"
                " if (!c) return [];"
                " return [...c.children].map(t => { const a = t.querySelector('a[href]');"
                " return { text: (t.innerText || '').trim(), href: a ? a.getAttribute('href') : null }; }); }"
            )
            browser.close()
    except Exception:
        print("[]")
        return

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
        out.append({"title": title, "text": full, "url": url, "fromIso": from_iso, "toIso": to_iso_value})

    print(json.dumps(out, ensure_ascii=False))


if __name__ == "__main__":
    main()
