"""Publish a reviewed source snapshot; Actions owns tests and release assets."""

import argparse
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import time

from build import ROOT, source_files

REPO = "crowveil/xboard-friend-invite"
URL = f"https://github.com/{REPO}.git"
NAME = "crowveil"
EMAIL = "330225440+crowveil@users.noreply.github.com"
BRANCH = "main"


class PublishError(RuntimeError):
    pass


def run(args, cwd=None, check=True):
    result = subprocess.run(
        args, cwd=cwd or ROOT, text=True, capture_output=True,
        env={**os.environ, "GH_HOST": "github.com", "GH_PROMPT_DISABLED": "1"},
    )
    if check and result.returncode:
        detail = result.stderr.strip() or result.stdout.strip() or f"exit {result.returncode}"
        raise PublishError(f"命令失败（{args[0]}）：{detail}")
    return result


def gh(*args, check=True):
    return run(["gh", *args], check=check)


def git(repo, *args, check=True):
    return run([
        "git", "-c", "credential.helper=",
        "-c", "credential.https://github.com.helper=!gh auth git-credential",
        "-c", "core.hooksPath=/dev/null", *args,
    ], cwd=repo, check=check)


def account():
    login = gh("api", "--hostname", "github.com", "user", "--jq", ".login").stdout.strip()
    if login != NAME:
        raise PublishError("gh 当前实际账号不是 crowveil；已停止，不会自动切换账号。")
    gh("auth", "status", "--hostname", "github.com")


def identity(repo):
    for key, expected in (("user.name", NAME), ("user.email", EMAIL)):
        if git(repo, "config", "--local", key).stdout.strip() != expected:
            raise PublishError("仓库本地 Git 身份不符合 crowveil 规则。")
    for key in ("GIT_AUTHOR_IDENT", "GIT_COMMITTER_IDENT"):
        if not git(repo, "var", key).stdout.startswith(f"{NAME} <{EMAIL}> "):
            raise PublishError("环境变量覆盖了 Git 作者或提交者；请清除覆盖后重试。")


def configure_identity(repo):
    for key, value in (("user.name", NAME), ("user.email", EMAIL),
                       ("commit.gpgsign", "false"), ("tag.gpgsign", "false")):
        git(repo, "config", "--local", key, value)
    identity(repo)


def remote(repo):
    # actions/checkout omits .git; allow both exact URLs without rewriting origin.
    allowed = {URL, URL.removesuffix(".git")}
    for args in (("remote", "get-url", "--all", "origin"),
                 ("remote", "get-url", "--push", "--all", "origin")):
        urls = git(repo, *args).stdout.strip().splitlines()
        if not urls or any(url not in allowed for url in urls):
            raise PublishError("origin 或 URL 重写规则不符合目标 HTTPS 仓库，已停止。")


def api_optional(path):
    result = gh("api", "--hostname", "github.com", path, check=False)
    if result.returncode == 0:
        return json.loads(result.stdout)
    if "HTTP 404" in result.stderr:
        return None
    raise PublishError(f"无法确认 GitHub 状态：{result.stderr.strip()}")


def remote_head():
    rows = git(ROOT, "ls-remote", "--heads", "--tags", URL).stdout.splitlines()
    refs = dict(reversed(row.split()) for row in rows)
    head = refs.get("refs/heads/main")
    if refs and head is None:
        raise PublishError("仓库已有其他分支或标签，但没有 main；请先确认仓库内容。")
    return head


def prepare_snapshot(destination):
    paths = source_files()
    wanted = {path.relative_to(ROOT).as_posix() for path in paths}
    for name in filter(None, git(destination, "ls-files", "-z").stdout.split("\0")):
        if name not in wanted:
            (destination / name).unlink()
    for path in paths:
        target = destination / path.relative_to(ROOT)
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(path, target)
        target.chmod(0o644)
    identity(destination)
    git(destination, "add", "--all")
    # Upstream test snapshots stay byte-for-byte original, including whitespace.
    git(destination, "diff", "--cached", "--check", "--", ".", ":(exclude)tests/upstream/**")


def validate_base(checkout, base, head, changed):
    if head is None:
        if base != "initial":
            raise PublishError("空仓库首次发布需要 RELEASE_BASE 为 initial。")
        return
    if base == "initial":
        # Retrying an interrupted first publish must preserve the root commit.
        parent = git(checkout, "show", "-s", "--format=%P", "HEAD").stdout.strip()
        if changed or parent:
            raise PublishError("远端已有源码；请整合 main 并更新 RELEASE_BASE，不能按首次发布覆盖。")
    elif changed and head != base:
        raise PublishError("远端 main 已变化，与 RELEASE_BASE 不一致；请先整合更新。")
    elif not changed and head != base:
        parent = git(checkout, "show", "-s", "--format=%P", "HEAD").stdout.strip()
        if parent != base:
            raise PublishError("远端历史不符合本次发布的中断恢复条件。")


def workflow_runs(workflow, commit, event):
    result = gh(
        "run", "list", "--repo", REPO, "--workflow", workflow, "--event", event,
        "--commit", commit, "--limit", "30",
        "--json", "databaseId,headBranch,headSha,status,conclusion", check=False,
    )
    if result.returncode:
        if "HTTP 404" in result.stderr:
            return []  # A newly pushed workflow can take a moment to appear.
        raise PublishError(f"无法读取 Actions：{result.stderr.strip()}")
    return json.loads(result.stdout)


def watch(run_id):
    print(f"https://github.com/{REPO}/actions/runs/{run_id}", flush=True)
    code = subprocess.call(
        ["gh", "run", "watch", str(run_id), "--repo", REPO, "--exit-status"],
        env={**os.environ, "GH_HOST": "github.com", "GH_PROMPT_DISABLED": "1"},
    )
    if code:
        raise PublishError(f"Actions run {run_id} 未成功；请查看上方链接，不会继续发布。")


def wait_for_tests(commit, attempts=60, delay=5):
    for _ in range(attempts):
        candidates = [r for r in workflow_runs("test.yml", commit, "push")
                      if r["headBranch"] == BRANCH and r["headSha"] == commit]
        if candidates:
            run_id = str(max(candidates, key=lambda r: r["databaseId"])["databaseId"])
            print(f"等待 main 测试：run {run_id}", flush=True)
            watch(run_id)
            return run_id
        time.sleep(delay)
    raise PublishError("等待 main 测试工作流创建超时；请确认仓库已允许 GitHub Actions。")


def dispatch_release(version, commit, attempts=60, delay=5):
    current = api_optional(f"repos/{REPO}/git/ref/heads/main")
    if current is None or current["object"]["sha"] != commit:
        raise PublishError("main 在测试期间已变化；不会发布其他提交。")
    before = {r["databaseId"] for r in workflow_runs("release.yml", commit, "workflow_dispatch")}
    account()
    gh("workflow", "run", "release.yml", "--repo", REPO, "--ref", BRANCH,
       "-f", f"version={version}", "-f", f"confirmation=PUBLISH v{version}",
       "-f", f"expected_commit={commit}")
    for _ in range(attempts):
        candidates = [r for r in workflow_runs("release.yml", commit, "workflow_dispatch")
                      if r["databaseId"] not in before and r["headSha"] == commit
                      and r["headBranch"] == BRANCH]
        if candidates:
            run_id = str(max(candidates, key=lambda r: r["databaseId"])["databaseId"])
            print(f"等待 GitHub 发布：run {run_id}", flush=True)
            watch(run_id)
            return run_id
        time.sleep(delay)
    raise PublishError("等待 Release 工作流创建超时。")


def publish(check_only=False):
    print("publish.sh：GitHub Actions 发布模式；本机无需 PHP、Composer、Node 或 npm。", flush=True)
    for command in ("git", "gh"):
        if shutil.which(command) is None:
            raise PublishError(f"缺少 {command}，请先安装。")
    manifest = json.loads((ROOT / "FriendInvite/config.json").read_text())
    version = manifest["version"]
    if not re.fullmatch(r"\d+\.\d+\.\d+", version) or manifest["code"] != "friend_invite":
        raise PublishError("版本或插件标识不正确。")
    base = (ROOT / "RELEASE_BASE").read_text().strip()
    if base != "initial" and not re.fullmatch(r"[0-9a-f]{40}", base):
        raise PublishError("RELEASE_BASE 必须为 initial 或基于的远端 main 完整 SHA。")
    notes = ROOT / f"docs/releases/v{version}.md"
    if not notes.is_file():
        raise PublishError(f"缺少发布说明 {notes.relative_to(ROOT)}。")
    print(run([sys.executable, "tools/build.py", "--check"]).stdout, end="")
    account()
    if git(ROOT, "ls-remote", "--get-url", URL).stdout.strip() not in {URL, URL.removesuffix(".git")}:
        raise PublishError("Git URL 被重写到其他仓库；脚本不会修改全局设置。")
    metadata = api_optional(f"repos/{REPO}")
    if metadata and (metadata["full_name"] != REPO or metadata.get("fork")):
        raise PublishError("远端不是预期的独立 crowveil 仓库。")
    head = remote_head() if metadata else None
    if metadata and head and metadata["default_branch"] != BRANCH and base != "initial":
        raise PublishError("远端默认分支不是 main；请先确认仓库。")
    tag = f"v{version}"
    tag_ref = api_optional(f"repos/{REPO}/git/ref/tags/{tag}") if head else None
    existing = api_optional(f"repos/{REPO}/releases/tags/{tag}") if head else None
    if existing and not existing.get("draft", False) and not check_only:
        raise PublishError("版本已经发布；请递增版本号，不会覆盖旧版本。")

    with tempfile.TemporaryDirectory(prefix="friend-invite-publish-") as directory:
        checkout = Path(directory) / "repo"
        if head:
            git(Path(directory), "clone", "--branch", BRANCH, "--single-branch",
                "--no-tags", URL, str(checkout))
            if git(checkout, "rev-parse", "HEAD").stdout.strip() != head:
                raise PublishError("main 在准备期间已变化；请重新检查。")
        else:
            checkout.mkdir()
            git(checkout, "init", "--initial-branch=main")
            git(checkout, "remote", "add", "origin", URL)
        configure_identity(checkout)
        remote(checkout)
        if head:
            print(run([sys.executable, str(ROOT / "tools/check_identity.py"),
                       "--repo", str(checkout), "--history"]).stdout, end="")
            print(git(checkout, "log", "-1", "--format=fuller").stdout)
        prepare_snapshot(checkout)
        changed = bool(git(checkout, "diff", "--cached", "--name-only").stdout.strip())
        validate_base(checkout, base, head, changed)
        if tag_ref is not None:
            from release import resolve_tag
            if changed or resolve_tag(tag_ref) != head:
                raise PublishError("已有标签不对应当前源码；请递增版本号，不会移动标签。")
        print("请检查下面的实际暂存差异，确认不包含真实凭据或私人信息：")
        print(git(checkout, "diff", "--cached", "--no-ext-diff", "--no-textconv").stdout, flush=True)
        if metadata is None:
            print(f"首次发布时将创建公开仓库：{REPO}")
        if check_only:
            print("检查完成；未创建仓库、推送代码或触发发布。")
            return
        expected = f"PUBLISH {tag}"
        if input(f"确认后请输入 {expected}：").strip() != expected:
            raise PublishError("确认文字不匹配，已取消。")
        account()
        if metadata is None:
            gh("repo", "create", REPO, "--public", "--description",
               "One-time friend invitations and Telegram administration for XBoard")
        if remote_head() != head:
            raise PublishError("远端 main 在确认期间已变化；未推送，请重新检查。")
        identity(checkout)
        if changed:
            git(checkout, "commit", "--no-gpg-sign", "-m", f"Prepare {tag} release")
            identity(checkout)
            print(run([sys.executable, str(ROOT / "tools/check_identity.py"),
                       "--repo", str(checkout), "--history"]).stdout, end="")
            print(git(checkout, "log", "-1", "--format=fuller").stdout)
            account()
            remote(checkout)
            git(checkout, "push", "origin", f"HEAD:refs/heads/{BRANCH}")
        if metadata is None or metadata["default_branch"] != BRANCH:
            account()
            gh("repo", "edit", REPO, "--default-branch", BRANCH)
        commit = git(checkout, "rev-parse", "HEAD").stdout.strip()
        wait_for_tests(commit)
        dispatch_release(version, commit)
        print(f"发布完成：https://github.com/{REPO}/releases/tag/{tag}")


def main():
    parser = argparse.ArgumentParser(description="审阅并推送源码，由 GitHub Actions 测试、打包和发布。")
    parser.add_argument("--check", action="store_true", help="只检查身份和差异，不进行远端写操作")
    args = parser.parse_args()
    try:
        publish(args.check)
    except (PublishError, OSError, ValueError, KeyError, EOFError, KeyboardInterrupt) as error:
        print(f"停止：{error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
