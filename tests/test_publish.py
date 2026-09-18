"""Offline publishing tests using real Git repositories and real ZIP builds."""

import contextlib
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
import build
import publish
import release


def result(stdout="", code=0, stderr=""):
    return subprocess.CompletedProcess([], code, stdout, stderr)


class GitSandbox(unittest.TestCase):
    def setUp(self):
        self.stack = contextlib.ExitStack()
        self.addCleanup(self.stack.close)
        self.stack.enter_context(patch.dict(os.environ, {
            "GIT_CONFIG_GLOBAL": os.devnull, "GIT_CONFIG_NOSYSTEM": "1",
        }))
        self.directory = Path(self.stack.enter_context(tempfile.TemporaryDirectory(prefix="friend-invite-test-")))
        self.real_git = publish.git

    def init(self, directory):
        directory.mkdir(parents=True, exist_ok=True)
        self.real_git(directory, "init", "--initial-branch=main")
        publish.configure_identity(directory)


class RemoteTests(GitSandbox):
    def test_clone_and_checkout_urls_are_accepted_unchanged(self):
        self.init(self.directory)
        for url in (publish.URL, publish.URL.removesuffix(".git")):
            with self.subTest(url=url):
                self.real_git(self.directory, "config", "--local", "remote.origin.url", url)
                release.remote(self.directory)
                self.assertEqual(self.real_git(self.directory, "config", "--local", "remote.origin.url").stdout.strip(), url)

    def test_wrong_and_extra_targets_and_rewrites_are_rejected(self):
        self.init(self.directory)
        for key in ("remote.origin.url", "remote.origin.pushurl"):
            for url in (
                "https://github.com/other/xboard-friend-invite.git",
                "https://github.com/crowveil/other.git",
                "https://github.com.example.test/crowveil/xboard-friend-invite.git",
                "http://github.com/crowveil/xboard-friend-invite.git",
                "git@github.com:crowveil/xboard-friend-invite.git",
                "https://credential@github.com/crowveil/xboard-friend-invite.git",
            ):
                with self.subTest(key=key, url=url):
                    self.real_git(self.directory, "config", "--local", "remote.origin.url", publish.URL)
                    self.real_git(self.directory, "config", "--local", "--unset-all", "remote.origin.pushurl", check=False)
                    self.real_git(self.directory, "config", "--local", key, url)
                    with self.assertRaises(publish.PublishError):
                        release.remote(self.directory)
        self.real_git(self.directory, "config", "--local", "remote.origin.pushurl", publish.URL)
        self.real_git(self.directory, "config", "--local", "--add", "remote.origin.pushurl", "https://example.test/unexpected.git")
        with self.assertRaises(publish.PublishError):
            release.remote(self.directory)
        self.real_git(self.directory, "config", "--local", "--unset-all", "remote.origin.pushurl")
        self.real_git(self.directory, "config", "--local", "url.https://example.test/.insteadOf", "https://github.com/")
        with self.assertRaises(publish.PublishError):
            release.remote(self.directory)


class PublishingFlowTests(GitSandbox):
    """Only GitHub API calls and Git network transport are replaced."""

    def setUp(self):
        super().setUp()
        self.source = self.directory / "source"
        self.bare = self.directory / "remote.git"
        original_root = build.ROOT
        for path in build.source_files():
            target = self.source / path.relative_to(original_root)
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(path, target)
        self.exists = False
        self.default_branch = "master"
        self.release_state = None
        self.assets = {}
        self.calls = []
        self.pushes = []
        self.fail_upload = False
        self.switch_root(self.source, self.stack)
        for module in (publish, release):
            self.stack.enter_context(patch.object(module, "git", side_effect=self.git))
            self.stack.enter_context(patch.object(module, "gh", side_effect=self.gh))
        self.stack.enter_context(patch.object(publish.shutil, "which", return_value="synthetic-installed-command"))
        self.stack.enter_context(patch("builtins.input", return_value="PUBLISH v0.1.0"))
        self.stack.enter_context(contextlib.redirect_stdout(io.StringIO()))

    def switch_root(self, root, stack):
        for module in (build, publish, release):
            stack.enter_context(patch.object(module, "ROOT", root))
        stack.enter_context(patch.object(build, "PLUGIN", root / "FriendInvite"))

    def create_remote(self):
        self.real_git(self.directory, "init", "--bare", str(self.bare))
        self.exists = True

    def head(self):
        return self.real_git(self.bare, "rev-parse", "--verify", "refs/heads/main", check=False).stdout.strip()

    def git(self, repo, *args, check=True):
        if args[:3] == ("ls-remote", "--heads", "--tags"):
            self.assertTrue(self.exists)
            return self.real_git(repo, *args[:-1], str(self.bare), check=check)
        if args[0] == "clone":
            self.assertIn(publish.URL, args)
            translated = [str(self.bare) if a == publish.URL else a for a in args]
            output = self.real_git(repo, *translated, check=check)
            self.real_git(Path(args[-1]), "config", "--local", "remote.origin.url", publish.URL)
            return output
        if args[0] == "push":
            self.assertFalse(any(a.startswith("--force") for a in args))
            self.pushes.append(args)
            return self.real_git(repo, *(str(self.bare) if a == "origin" else a for a in args), check=check)
        return self.real_git(repo, *args, check=check)

    def gh(self, *args, check=True):
        self.calls.append(args)
        if args[0] == "api":
            if "user" in args:
                return result("crowveil\n")
            endpoint = next(a for a in args if a.startswith("repos/"))
            if endpoint == f"repos/{publish.REPO}":
                data = {"full_name": publish.REPO, "default_branch": self.default_branch, "fork": False} if self.exists else None
            elif "/git/ref/heads/main" in endpoint:
                data = {"object": {"type": "commit", "sha": self.head()}}
            elif "/git/ref/tags/" in endpoint:
                tag = endpoint.rsplit("/", 1)[-1]
                ref = self.real_git(self.bare, "rev-parse", "--verify", f"refs/tags/{tag}", check=False)
                data = {"object": {"type": "tag", "sha": ref.stdout.strip()}} if ref.returncode == 0 else None
            elif "/git/tags/" in endpoint:
                obj = self.real_git(self.bare, "cat-file", "-p", endpoint.rsplit("/", 1)[-1]).stdout.splitlines()
                data = {"object": {"sha": obj[0].split()[1], "type": obj[1].split()[1]}}
            elif "/releases/tags/" in endpoint:
                data = self.release_state
            elif "/actions/workflows/test.yml/runs" in endpoint:
                self.assertIn("--method", args)
                self.assertIn("GET", args)
                data = {"workflow_runs": [{"id": 1, "head_sha": self.head(), "head_branch": "main", "event": "push", "conclusion": "success"}]}
            else:
                self.fail(f"Unexpected API: {args}")
            return result(json.dumps(data)) if data is not None else result(code=1, stderr="HTTP 404: Not Found")
        if args[:2] == ("auth", "status"):
            return result()
        if args[:2] == ("repo", "create"):
            self.assertFalse(self.exists)
            self.assertIn("--public", args)
            self.create_remote()
            return result()
        if args[:2] == ("repo", "edit"):
            self.assertTrue(self.head())
            self.default_branch = "main"
            self.real_git(self.bare, "symbolic-ref", "HEAD", "refs/heads/main")
            return result()
        if args[:2] == ("release", "create"):
            self.assertIsNone(self.release_state)
            self.assertIn("--draft", args)
            self.assertIn("--verify-tag", args)
            self.release_state = {"draft": True, "immutable": False}
            return result()
        if args[:2] == ("release", "view"):
            return result(json.dumps({"assets": [{"name": n} for n in self.assets]}))
        if args[:2] == ("release", "upload"):
            self.assertTrue(self.release_state["draft"])
            self.assertNotIn("--clobber", args)
            if self.fail_upload and len(self.assets) == 1:
                self.fail_upload = False
                raise publish.PublishError("synthetic upload interruption")
            asset = Path(args[3])
            self.assertNotIn(asset.name, self.assets)
            self.assets[asset.name] = asset.read_bytes()
            return result()
        if args[:2] == ("release", "download"):
            name = args[args.index("--pattern") + 1]
            (Path(args[args.index("--dir") + 1]) / name).write_bytes(self.assets[name])
            return result()
        if args[:2] == ("release", "edit"):
            self.assertTrue(self.release_state["draft"])
            self.assertEqual(len(self.assets), 3)
            self.release_state = {"draft": False, "immutable": True}
            return result()
        self.fail(f"Unexpected gh call: {args}")

    def seed(self):
        with patch.object(publish, "wait_for_tests"), patch.object(publish, "dispatch_release"):
            publish.publish()
        return self.head()

    def test_check_does_not_create_repo_commit_push_or_dispatch(self):
        with patch.object(publish, "wait_for_tests") as tests, patch.object(publish, "dispatch_release") as dispatch:
            publish.publish(check_only=True)
            self.assertFalse(self.exists)
            self.assertEqual(self.pushes, [])
            self.assertFalse(any(c[:2] == ("repo", "create") for c in self.calls))
            tests.assert_not_called()
            dispatch.assert_not_called()

    def test_first_publish_failed_tests_and_retry_reuse_the_same_commit(self):
        with patch.object(publish, "wait_for_tests", side_effect=publish.PublishError("synthetic Tests failure")) as tests, patch.object(publish, "dispatch_release") as dispatch:
            with self.assertRaisesRegex(publish.PublishError, "Tests failure"):
                publish.publish()
            commit = self.head()
            dispatch.assert_not_called()
            self.assertEqual(len(self.pushes), 1)
            self.assertEqual(self.real_git(self.bare, "rev-list", "--count", "main").stdout.strip(), "1")
            tests.side_effect = None
            dispatch.side_effect = publish.PublishError("synthetic dispatch interruption")
            with self.assertRaisesRegex(publish.PublishError, "dispatch interruption"):
                publish.publish()
            dispatch.side_effect = None
            publish.publish()
            self.assertEqual(self.head(), commit)
            self.assertEqual(len(self.pushes), 1)
            dispatch.assert_called_with("0.1.0", commit)
            self.assertEqual(self.default_branch, "main")
            metadata = self.real_git(self.bare, "log", "-1", "--format=%an|%ae|%cn|%ce").stdout.strip()
            self.assertEqual(metadata, f"{publish.NAME}|{publish.EMAIL}|{publish.NAME}|{publish.EMAIL}")

    def test_existing_empty_repository_can_be_published(self):
        self.create_remote()
        self.seed()
        self.assertFalse(any(c[:2] == ("repo", "create") for c in self.calls))
        self.assertEqual(len(self.pushes), 1)

    def test_initial_cannot_overwrite_existing_source(self):
        self.seed()
        with (self.source / "README.md").open("a") as stream:
            stream.write("\nSynthetic changed source\n")
        with self.assertRaisesRegex(publish.PublishError, "整合 main"):
            publish.publish()
        self.assertEqual(len(self.pushes), 1)

    def test_published_version_is_not_overwritten(self):
        self.seed()
        self.release_state = {"draft": False}
        with self.assertRaisesRegex(publish.PublishError, "版本已经发布"):
            publish.publish()
        self.assertEqual(len(self.pushes), 1)

    def test_real_release_draft_upload_resume_and_immutable_verification(self):
        commit = self.seed()
        checkout = self.directory / "runner"
        self.real_git(self.directory, "clone", str(self.bare), str(checkout))
        # Exact actions/checkout URL, deliberately without .git.
        self.real_git(checkout, "config", "--local", "remote.origin.url", publish.URL.removesuffix(".git"))
        env = dict(INPUT_VERSION="0.1.0", INPUT_COMMIT=commit, INPUT_CONFIRMATION="PUBLISH v0.1.0",
                   GITHUB_REPOSITORY=publish.REPO, GITHUB_ACTOR="crowveil", GITHUB_TRIGGERING_ACTOR="crowveil",
                   GITHUB_REF="refs/heads/main", GITHUB_SHA=commit, GITHUB_EVENT_NAME="workflow_dispatch")
        with contextlib.ExitStack() as stack:
            self.switch_root(checkout, stack)
            stack.enter_context(patch.dict(os.environ, env))
            self.fail_upload = True
            with self.assertRaisesRegex(publish.PublishError, "upload interruption"):
                release.release()
            self.assertTrue(self.release_state["draft"])
            self.assertEqual(len(self.assets), 1)
            tag_object = self.real_git(self.bare, "rev-parse", "v0.1.0").stdout.strip()
            release.release()
            self.assertFalse(self.release_state["draft"])
            self.assertEqual(self.real_git(self.bare, "rev-parse", "v0.1.0^{commit}").stdout.strip(), commit)
            self.assertEqual(self.real_git(self.bare, "rev-parse", "v0.1.0").stdout.strip(), tag_object)
            self.assertEqual(len(self.pushes), 2)  # One main push and one tag push.
            self.assertEqual(set(self.assets), {"FriendInvite-0.1.0.zip", "xboard-friend-invite-0.1.0-source.zip", "SHA256SUMS"})
            self.assertEqual(self.assets["SHA256SUMS"], (checkout / "dist/0.1.0/SHA256SUMS").read_bytes())
            with zipfile.ZipFile(io.BytesIO(self.assets["xboard-friend-invite-0.1.0-source.zip"])) as archive:
                names = archive.namelist()
                self.assertIn("xboard-friend-invite-0.1.0/publish.sh", names)
                self.assertIn("xboard-friend-invite-0.1.0/.github/workflows/release.yml", names)
                self.assertFalse(any(n.endswith(".zip") or "/.git/" in n or "/runtime/" in n for n in names))
            previous = len(self.calls)
            release.release()  # Already published and immutable: read-only verification.
            writes = [c for c in self.calls[previous:] if c[:2] in (("release", "upload"), ("release", "edit"), ("release", "create"))]
            self.assertEqual(writes, [])
            self.assets["FriendInvite-0.1.0.zip"] = b"unexpected asset"
            with self.assertRaisesRegex(publish.PublishError, "Existing asset differs"):
                release.release()


class GuardTests(unittest.TestCase):
    def test_wrong_account_stops_without_switching(self):
        with patch.object(publish, "gh", return_value=result("other\n")) as gh:
            with self.assertRaisesRegex(publish.PublishError, "不是 crowveil"):
                publish.account()
            self.assertEqual(gh.call_count, 1)

    def test_wrong_identity_stops(self):
        with patch.object(publish, "git", return_value=result("other")):
            with self.assertRaises(publish.PublishError):
                publish.identity(Path("."))

    def test_failed_tests_other_sha_and_newer_failure_cannot_release(self):
        passed = {"id": 1, "head_sha": "a" * 40, "head_branch": "main", "event": "push", "conclusion": "success"}
        cases = [[], [dict(passed, head_sha="b" * 40)], [dict(passed, event="pull_request")],
                 [passed, dict(passed, id=2, conclusion="failure")]]
        for runs in cases:
            with self.subTest(runs=runs), patch.object(release, "gh", return_value=result(json.dumps({"workflow_runs": runs}))):
                with self.assertRaises(publish.PublishError):
                    release.require_tests("a" * 40)
        with patch.object(release, "gh", return_value=result(json.dumps({"workflow_runs": [passed]}))) as gh:
            release.require_tests("a" * 40)
            self.assertIn("--method", gh.call_args.args)
            self.assertIn("GET", gh.call_args.args)

    def test_dispatch_context_requires_exact_actor_event_and_commit(self):
        env = dict(INPUT_VERSION="0.1.0", INPUT_COMMIT="a" * 40, INPUT_CONFIRMATION="PUBLISH v0.1.0",
                   GITHUB_REPOSITORY=publish.REPO, GITHUB_ACTOR="crowveil", GITHUB_TRIGGERING_ACTOR="crowveil",
                   GITHUB_REF="refs/heads/main", GITHUB_SHA="a" * 40, GITHUB_EVENT_NAME="workflow_dispatch")
        self.assertEqual(release.validate_request(env), ("0.1.0", "a" * 40))
        for key in env:
            with self.subTest(key=key), self.assertRaises(publish.PublishError):
                release.validate_request({**env, key: "unexpected"})

    def test_main_change_stops_dispatch(self):
        with patch.object(publish, "api_optional", return_value={"object": {"sha": "other"}}), patch.object(publish, "gh") as gh:
            with self.assertRaises(publish.PublishError):
                publish.dispatch_release("0.1.0", "a" * 40)
            gh.assert_not_called()

    def test_dispatch_passes_full_commit_and_watches_only_new_matching_run(self):
        commit = "a" * 40
        old = {"databaseId": 1, "headSha": commit, "headBranch": "main"}
        new = {**old, "databaseId": 2}
        with patch.object(publish, "api_optional", return_value={"object": {"sha": commit}}), patch.object(publish, "account"), \
                patch.object(publish, "workflow_runs", side_effect=[[old], [old, new]]), \
                patch.object(publish, "gh", return_value=result()) as gh, patch.object(publish, "watch") as watch:
            self.assertEqual(publish.dispatch_release("0.1.0", commit, attempts=1, delay=0), "2")
            self.assertIn(f"expected_commit={commit}", gh.call_args.args)
            self.assertIn("confirmation=PUBLISH v0.1.0", gh.call_args.args)
            watch.assert_called_once_with("2")

    def test_wait_for_latest_matching_tests(self):
        runs = [{"databaseId": 1, "headSha": "abc", "headBranch": "main"},
                {"databaseId": 2, "headSha": "abc", "headBranch": "main"},
                {"databaseId": 3, "headSha": "abc", "headBranch": "other"}]
        with patch.object(publish, "workflow_runs", return_value=runs), patch.object(publish, "watch") as watch:
            publish.wait_for_tests("abc", attempts=1, delay=0)
            watch.assert_called_once_with("2")


if __name__ == "__main__":
    unittest.main()
