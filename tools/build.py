"""Check console assets and build reproducible plugin/source archives."""

import argparse
import hashlib
import json
import re
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / "FriendInvite"
ROOT_FILES = {
    ".editorconfig",
    ".gitattributes",
    ".gitignore",
    ".prettierignore",
    ".prettierrc.json",
    "README.md",
    "LICENSE",
    "CHANGELOG.md",
    "composer.json",
    "composer.lock",
    "package.json",
    "package-lock.json",
    "phpunit.xml",
    "pint.json",
    "AGENTS.md",
    "publish.sh",
    "RELEASE_BASE",
}
SOURCE_DIRS = {"FriendInvite", "docs", "tests", "tools", ".github"}
EXCLUDED = {
    "runtime",
    "__pycache__",
    "node_modules",
    "vendor",
    ".git",
    ".phpunit.cache",
}
EXTENSIONS = {".php", ".js", ".cjs", ".css", ".html", ".json", ".py", ".md", ".yml"}


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def source_files():
    result = []
    for p in sorted(ROOT.rglob("*")):
        rel = p.relative_to(ROOT)
        if rel.parts[0] not in SOURCE_DIRS and str(rel) not in ROOT_FILES:
            continue
        if EXCLUDED.intersection(rel.parts) or not p.is_file():
            continue
        if p.name.startswith((".env", "credentials", "secrets", "config.local.", "id_rsa", "id_ed25519")):
            continue
        if p.is_symlink():
            raise ValueError(f"Release input is a symlink: {rel}")
        if str(rel) in ROOT_FILES or p.suffix in EXTENSIONS or p.name == "LICENSE":
            result.append(p)
    return result


def archive(destination, members):
    with zipfile.ZipFile(destination, "w", zipfile.ZIP_DEFLATED) as z:
        for name, data in sorted(members.items()):
            item = zipfile.ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))
            item.compress_type = zipfile.ZIP_DEFLATED
            item.external_attr = 0o100644 << 16
            z.writestr(item, data)
    with zipfile.ZipFile(destination) as z:
        if z.testzip():
            raise ValueError("Invalid archive")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true", help="Check assets without modifying files")
    parser.add_argument("--package", action="store_true", help="Build plugin/source ZIPs and SHA256SUMS")
    args = parser.parse_args()
    meta = json.loads((PLUGIN / "config.json").read_text())
    version = meta["version"]
    if meta["code"] != "friend_invite" or not re.fullmatch(r"\d+\.\d+\.\d+", version):
        raise ValueError("Invalid plugin identity/version")
    # Cache-bust browser assets whenever their content changes.
    html_path = PLUGIN / "resources/assets/console.html"
    original = html_path.read_text()
    html = original
    for filename in ("console.js", "console.css"):
        html, count = re.subn(
            re.escape(filename) + r'(?:\?v=[^"]+)?',
            filename + "?v=" + digest(html_path.parent / filename)[:12],
            html,
        )
        if count != 1:
            raise ValueError(f"Expected one asset reference: {filename}")
    if args.check and html != original:
        raise SystemExit("Console hashes are stale; run python3 tools/build.py")
    if not args.check:
        html_path.write_text(html)
    print(f"Console assets verified for {version}")
    if not args.package:
        return
    source_paths = source_files()
    required = {
        "publish.sh", "tools/publish.py", "tools/release.py", "tools/check_identity.py",
        "RELEASE_BASE", ".github/workflows/test.yml", ".github/workflows/release.yml",
        f"docs/releases/v{version}.md",
    }
    actual = {p.relative_to(ROOT).as_posix() for p in source_paths}
    if required - actual:
        raise ValueError(f"Missing release inputs: {sorted(required - actual)}")
    destination = ROOT / "dist" / version
    destination.mkdir(parents=True, exist_ok=True)
    plugin_zip = destination / f"FriendInvite-{version}.zip"
    source_zip = destination / f"xboard-friend-invite-{version}-source.zip"
    archive(
        plugin_zip,
        {
            p.relative_to(ROOT).as_posix(): p.read_bytes()
            for p in source_paths
            if p.is_relative_to(PLUGIN)
        },
    )
    archive(
        source_zip,
        {
            f"xboard-friend-invite-{version}/"
            + p.relative_to(ROOT).as_posix(): p.read_bytes()
            for p in source_paths
        },
    )
    sums = "".join(f"{digest(p)}  {p.name}\n" for p in (plugin_zip, source_zip))
    (destination / "SHA256SUMS").write_text(sums)
    print(sums, end="")


if __name__ == "__main__":
    main()
