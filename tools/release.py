"""GitHub Actions worker: verify Tests, build assets and publish a draft."""

import hashlib
import json
import os
from pathlib import Path
import re
import sys
import tempfile

from publish import EMAIL, NAME, REPO, ROOT, PublishError, api_optional, configure_identity, gh, git, identity, remote


def sha256(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def resolve_tag(ref):
    obj = ref["object"]
    for _ in range(8):
        if obj["type"] == "commit":
            return obj["sha"]
        if obj["type"] != "tag":
            break
        obj = json.loads(gh("api", f"repos/{REPO}/git/tags/{obj['sha']}").stdout)["object"]
    raise PublishError("Cannot resolve release tag.")


def validate_request(env):
    version, commit = env.get("INPUT_VERSION", ""), env.get("INPUT_COMMIT", "")
    if not re.fullmatch(r"\d+\.\d+\.\d+", version):
        raise PublishError("Invalid release version.")
    if not re.fullmatch(r"[0-9a-f]{40}", commit):
        raise PublishError("Expected commit must be a full SHA.")
    if (env.get("GITHUB_REPOSITORY") != REPO or env.get("GITHUB_ACTOR") != NAME
            or env.get("GITHUB_TRIGGERING_ACTOR") != NAME
            or env.get("GITHUB_REF") != "refs/heads/main" or env.get("GITHUB_SHA") != commit
            or env.get("GITHUB_EVENT_NAME") != "workflow_dispatch"):
        raise PublishError("Unexpected repository, actor, event, branch or commit.")
    if env.get("INPUT_CONFIRMATION") != f"PUBLISH v{version}":
        raise PublishError("Release confirmation does not match.")
    return version, commit


def require_tests(commit):
    runs = json.loads(gh(
        "api", "--method", "GET", f"repos/{REPO}/actions/workflows/test.yml/runs",
        "-f", f"head_sha={commit}", "-f", "event=push", "-f", "per_page=100",
    ).stdout)["workflow_runs"]
    matches = [r for r in runs if r["head_sha"] == commit
               and r["head_branch"] == "main" and r["event"] == "push"]
    if not matches or max(matches, key=lambda r: r["id"])["conclusion"] != "success":
        raise PublishError("Latest Tests run for this exact main commit has not succeeded.")


def sync_assets(tag, assets, published):
    metadata = json.loads(gh("release", "view", tag, "--repo", REPO, "--json", "assets").stdout)
    actual = {a["name"] for a in metadata["assets"]}
    if actual - {p.name for p in assets}:
        raise PublishError("Release contains unexpected assets; review before continuing.")
    with tempfile.TemporaryDirectory(prefix="friend-invite-release-") as directory:
        for path in assets:
            downloaded = Path(directory) / path.name
            if path.name in actual:
                gh("release", "download", tag, "--repo", REPO,
                   "--pattern", path.name, "--dir", directory)
                if sha256(downloaded) != sha256(path):
                    raise PublishError(f"Existing asset differs: {path.name}; refusing to overwrite.")
                continue
            if published:
                raise PublishError(f"Published release is missing asset: {path.name}")
            gh("release", "upload", tag, str(path), "--repo", REPO)
            gh("release", "download", tag, "--repo", REPO,
               "--pattern", path.name, "--dir", directory)
            if sha256(downloaded) != sha256(path):
                raise PublishError(f"Uploaded asset failed verification: {path.name}")


def release():
    version, commit = validate_request(os.environ)
    manifest = json.loads((ROOT / "FriendInvite/config.json").read_text())
    if manifest["version"] != version or manifest["code"] != "friend_invite":
        raise PublishError("Manifest does not match the requested release.")
    if git(ROOT, "rev-parse", "HEAD").stdout.strip() != commit:
        raise PublishError("Checkout does not match the tested commit.")
    notes = ROOT / "docs/releases" / f"v{version}.md"
    if not notes.is_file():
        raise PublishError("Release notes are missing.")
    current = api_optional(f"repos/{REPO}/git/ref/heads/main")
    if current is None or current["object"]["sha"] != commit:
        raise PublishError("main changed after the publish request; refusing another commit.")
    require_tests(commit)
    configure_identity(ROOT)
    remote(ROOT)
    if git(ROOT, "log", "-1", "--format=%an|%ae|%cn|%ce").stdout.strip() != f"{NAME}|{EMAIL}|{NAME}|{EMAIL}":
        raise PublishError("Release commit identity does not match crowveil.")
    print(git(ROOT, "log", "-1", "--format=fuller").stdout)
    tag = f"v{version}"
    ref = api_optional(f"repos/{REPO}/git/ref/tags/{tag}")
    if ref and resolve_tag(ref) != commit:
        raise PublishError("Tag points to another commit; refusing to overwrite it.")
    existing = api_optional(f"repos/{REPO}/releases/tags/{tag}")
    if existing and ref is None:
        raise PublishError("Release exists without its tag; review before continuing.")

    from build import main as build
    original_args = sys.argv
    try:
        sys.argv = ["build.py", "--check", "--package"]
        build()
    finally:
        sys.argv = original_args
    if git(ROOT, "status", "--porcelain", "--untracked-files=no").stdout.strip():
        raise PublishError("Build modified tracked sources.")

    if ref is None:
        identity(ROOT)
        remote(ROOT)
        git(ROOT, "tag", "--annotate", tag, "--message", f"XBoard Friend Invite {version}", commit)
        git(ROOT, "push", "origin", f"refs/tags/{tag}:refs/tags/{tag}")
    if existing is None:
        gh("release", "create", tag, "--repo", REPO, "--verify-tag", "--draft",
           "--title", f"XBoard Friend Invite {version}", "--notes-file", str(notes))
        existing = {"draft": True}
    dist = ROOT / "dist" / version
    assets = [dist / f"FriendInvite-{version}.zip",
              dist / f"xboard-friend-invite-{version}-source.zip", dist / "SHA256SUMS"]
    # Published releases, including immutable ones, are only verified on retries.
    sync_assets(tag, assets, published=not existing["draft"])
    if existing["draft"]:
        identity(ROOT)
        gh("release", "edit", tag, "--repo", REPO, "--draft=false", "--latest",
           "--title", f"XBoard Friend Invite {version}", "--notes-file", str(notes))
    print(f"Verified release: https://github.com/{REPO}/releases/tag/{tag}")


if __name__ == "__main__":
    try:
        release()
    except (PublishError, OSError, ValueError, KeyError) as error:
        sys.exit(str(error))
