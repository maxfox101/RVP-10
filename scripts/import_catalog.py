# -*- coding: utf-8 -*-
"""Импорт каталога БазМет из database/data/catalog.json в WordPress."""
import json
import subprocess
import sys
from pathlib import Path

WP_ROOT = Path("/Users/maksim/Documents/bazmet-wp")
CATALOG = Path("/Users/maksim/Documents/database/data/catalog.json")
IMAGES = Path("/Users/maksim/Documents/react/public/images")

TYPE_LABEL = {
    "nut": "Гайки",
    "bolt": "Болты",
    "screw": "Винты",
    "rivet": "Заклёпки",
    "washer": "Шайбы",
}


def wp(*args):
    cmd = [
        "php",
        "-d",
        "display_errors=0",
        "-d",
        "error_reporting=0",
        str(WP_ROOT / "wp-cli.phar"),
        *args,
    ]
    r = subprocess.run(cmd, cwd=WP_ROOT, capture_output=True, text=True)
    out = (r.stdout or "").strip()
    err = (r.stderr or "").strip()
    if r.returncode != 0:
        print("FAIL", args, err or out, file=sys.stderr)
        raise SystemExit(r.returncode)
    # filter deprecation noise
    lines = [ln for ln in out.splitlines() if "Deprecated" not in ln and ln.strip()]
    return "\n".join(lines).strip()


def main():
    data = json.loads(CATALOG.read_text(encoding="utf-8"))

    # theme supports thumbnails for CPT already via supports thumbnail

    # delete old products
    ids = wp("post", "list", "--post_type=bazmet_product", "--format=ids")
    if ids:
        for pid in ids.split():
            wp("post", "delete", pid, "--force")
            print("deleted product", pid)

    # delete old services CPT if any
    ids = wp("post", "list", "--post_type=bazmet_service", "--format=ids")
    if ids:
        for pid in ids.split():
            wp("post", "delete", pid, "--force")

    # taxonomy terms: ensure by slug
    term_ids = {}
    for slug, title in TYPE_LABEL.items():
        existing = wp("term", "list", "bazmet_type", f"--slug={slug}", "--field=term_id")
        if existing:
            tid = existing.splitlines()[0].strip()
        else:
            tid = wp("term", "create", "bazmet_type", title, f"--slug={slug}", "--porcelain")
        term_ids[slug] = tid
        print("term", slug, tid, title)

    # products
    for p in data["products"]:
        specs = p.get("specs") or []
        body_parts = [p.get("description") or ""]
        if specs:
            body_parts.append("<h3>Характеристики</h3><ul>")
            for s in specs:
                body_parts.append(f"<li>{s}</li>")
            body_parts.append("</ul>")
        # варианты выбора — только в селектах (_bazmet_fields), не текстом в контенте
        content = "\n".join(body_parts)
        excerpt = (p.get("description") or "")[:180]
        post_name = p["id"]
        pid = wp(
            "post",
            "create",
            "--post_type=bazmet_product",
            f"--post_title={p['title']}",
            f"--post_name={post_name}",
            "--post_status=publish",
            f"--post_excerpt={excerpt}",
            f"--post_content={content}",
            "--porcelain",
        )
        tid = term_ids[p["type"]]
        wp("post", "term", "set", pid, "bazmet_type", tid, "--by=id")

        fields_json = json.dumps(p.get("fields") or [], ensure_ascii=False)
        wp("post", "meta", "update", pid, "_bazmet_fields", fields_json)

        img_name = Path(p.get("img") or "").name
        img_path = IMAGES / img_name
        if img_path.exists():
            mid = wp(
                "media",
                "import",
                str(img_path),
                f"--post_id={pid}",
                "--featured_image",
                f"--title={p['title']}",
                "--porcelain",
            )
            print("product", pid, p["id"], "img", mid)
        else:
            print("product", pid, p["id"], "NO IMG", img_name)

    # services
    for s in data["services"]:
        sid = wp(
            "post",
            "create",
            "--post_type=bazmet_service",
            f"--post_title={s['title']}",
            "--post_status=publish",
            f"--post_content={s['text']}",
            "--porcelain",
        )
        print("service", sid, s["title"])

    print("DONE products", len(data["products"]), "services", len(data["services"]))


if __name__ == "__main__":
    main()
