# Project guidance

- Preserve the plugin code `friend_invite`, namespace and installation directory `FriendInvite`, routes, storage paths and database tables unless a migration is explicitly requested.
- Keep `FriendInvite/config.json` as the version source. Update the changelog and release notes for releases. Run `python3 tools/build.py` after changing console assets.
- Run PHPUnit and DOM tests after relevant changes. Run `python3 -m unittest discover -s tests -p 'test_publish.py' -v` after publishing changes. Use synthetic credentials and preserve attribution in `tests/upstream`.
- Keep real invite links, emails, Bot Tokens, diagnostics and private identity information out of commits and archives. Review the actual staged diff, not only ignore rules.

## Git identity when acting for crowveil

Use only repository-local `user.name=crowveil` and `user.email=330225440+crowveil@users.noreply.github.com`. Before Git writes, verify both local settings and effective author / committer. Before publishing, inspect commit metadata, staged changes, remote and the authenticated GitHub account. The intended repository is `crowveil/xboard-friend-invite`.

Do not change global identity, reuse another identity's signing keys or Git history, force-push, change an existing remote, switch accounts, create credentials or authorize another app without explicit approval. If identity or authentication cannot be verified, stop before publishing and explain the concrete blocker. Preserve third-party attribution.

`python3 tools/check_identity.py --history` checks local metadata; it does not establish GitHub authentication. Other contributors retain their own identities and third-party copyrights.
