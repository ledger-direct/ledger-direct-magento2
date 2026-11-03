#!/usr/bin/env python3
"""
Copies the finished articles from TryoutCatalog (the one source, kept outside the repo) into
dev/try/catalog/: images scaled to 1200 px on the long edge as JPEG with every bit of metadata
removed, and a catalog.json in the Gauklermarkt layout (number, slug, price, images, and per language
name, tagline, description). Run it again whenever TryoutCatalog changes; never edit the copies here.

    python3 dev/try/catalog/sync.py ~/Documents/LedgerDirect/TryoutCatalog

An article is a file NN-<slug>.md with its images next to it; files and folders starting with "_"
are skipped, and so is an article whose first image is missing. Without price_eur the README's rule
applies: small amounts between 1 and 5 EUR, here 2, 3, 4 … EUR in the order of the files.
Needs Pillow.
"""
import json
import re
import shutil
import sys
from pathlib import Path

from PIL import Image

EDGE = 1200
HERE = Path(__file__).resolve().parent


def front_matter(text: str) -> tuple[dict, str]:
    _, head, body = text.split('---', 2)
    data, images = {}, []
    for line in head.splitlines():
        line = line.split(' #', 1)[0].rstrip()
        if not line.strip() or line.lstrip().startswith('#'):
            continue
        if m := re.match(r'\s+- file:\s*(.+)', line):
            images.append({'file': m.group(1).strip()})
        elif m := re.match(r'\s+alt_en:\s*"?(.*?)"?$', line):
            images[-1]['alt'] = m.group(1)
        elif m := re.match(r'(\w+):\s*(.*)', line):
            data[m.group(1)] = m.group(2).strip()
    data['images'] = images
    return data, body


def sections(body: str) -> dict:
    found = {}
    for m in re.finditer(r'^## (.+?)\n(.*?)(?=^## |\Z)', body, re.S | re.M):
        found[m.group(1).strip()] = m.group(2).strip()
    return found


def slugify(text: str) -> str:
    return re.sub(r'[^a-z0-9]+', '-', text.lower()).strip('-')


def main(source: Path) -> None:
    articles = sorted(p for p in source.glob('[0-9]*.md') if not p.name.startswith('_'))
    # Everything is built in images.new first. images/ and catalog.json are replaced only once every
    # article converted and the checks passed; any failure (missing source, no usable article, a
    # duplicate SKU, a missing name, an unreadable picture) leaves the current catalogue untouched.
    out_images = HERE / 'images.new'
    shutil.rmtree(out_images, ignore_errors=True)
    out_images.mkdir()
    try:
        products = build(source, articles, out_images)
    except BaseException:
        shutil.rmtree(out_images, ignore_errors=True)
        raise
    publish(products, out_images)


def build(source: Path, articles: list, out_images: Path) -> list:
    products = []
    for index, path in enumerate(articles):
        data, body = front_matter(path.read_text(encoding='utf-8'))
        text = sections(body)
        if not data['images'] or not (source / data['images'][0]['file']).is_file():
            print(f'skipped {path.name}: first image missing')
            continue
        slug = slugify(text['Name (en)'])
        images = []
        for number, image in enumerate(data['images'], start=1):
            src = source / image['file']
            if not src.is_file():
                continue
            name = f"{slug}-{number:02d}.jpg"
            with Image.open(src) as picture:
                picture = picture.convert('RGB')
                picture.thumbnail((EDGE, EDGE))
                # A fresh image object: no EXIF, IPTC, XMP or ICC profile is carried over.
                clean = Image.new('RGB', picture.size)
                clean.paste(picture)
                clean.save(out_images / name, 'JPEG', quality=85, optimize=True, progressive=True)
            images.append({'file': name, 'alt': image.get('alt', text['Name (en)'])})
        product = {
            'slug': slug,
            'number': data['sku'],
            'price': float(data['price_eur']) if data.get('price_eur') else float(min(2 + index, 5)),
            'facts': {key: data[key] for key in ('scale', 'material', 'finish') if data.get(key)},
            'images': images,
        }
        for language, suffix in (('en-GB', 'en'), ('de-DE', 'de')):
            if f'Name ({suffix})' in text:
                product[language] = {
                    'name': text[f'Name ({suffix})'],
                    'tagline': text.get(f'Short ({suffix})', ''),
                    'description': text.get(f'Description ({suffix})', ''),
                }
        products.append(product)
        print(f"{product['number']}  {product['en-GB']['name']}  {product['price']:.2f} EUR  {len(images)} image(s)")

    if not products:
        sys.exit(f'no article with a picture in {source}; catalogue left unchanged')
    numbers = [p['number'] for p in products]
    if len(numbers) != len(set(numbers)):
        sys.exit('two articles share an SKU; every article needs its own (TryoutCatalog/README.md, section 2)')
    return products


def publish(products: list, new_images: Path) -> None:
    catalog = {
        '_comment': 'Generated by sync.py from TryoutCatalog; do not edit. Miniatures for the try shop, '
                    'ordinary articles paid like any other. Licence: see NOTICE.md.',
        'category': {'url_key': 'miniatures', 'en-GB': {'name': 'Miniatures'}, 'de-DE': {'name': 'Miniaturen'}},
        'closing': {
            'en-GB': 'Demo article in a shop on the XRP Ledger testnet — no real money, nothing is shipped.',
            'de-DE': 'Demo-Artikel in einem Shop im XRP-Ledger-Testnet — kein echtes Geld, nichts wird versendet.',
        },
        'products': products,
    }
    staged_json = HERE / 'catalog.json.new'
    staged_json.write_text(json.dumps(catalog, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    images, old = HERE / 'images', HERE / 'images.old'
    shutil.rmtree(old, ignore_errors=True)
    if images.exists():
        images.rename(old)
    new_images.rename(images)
    staged_json.replace(HERE / 'catalog.json')
    shutil.rmtree(old, ignore_errors=True)


if __name__ == '__main__':
    main(Path(sys.argv[1] if len(sys.argv) > 1 else '~/Documents/LedgerDirect/TryoutCatalog').expanduser())
