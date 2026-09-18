# 发布指南

## 交付结构

只分发一个完整源码 ZIP，解压后是 `xboard-friend-invite-0.1.0/`。根目录直接包含 `publish.sh`、`FriendInvite/`、测试、文档和工作流，不包含其他 ZIP、Git 历史、开发依赖或运行数据。

安装插件的人从 GitHub Release 下载 `FriendInvite-0.1.0.zip`。这是 Actions 根据经过测试的同一份源码生成的独立附件。

## Debian 首次发布

本机需要 Git、Python 3.9+、GitHub CLI。无需 PHP、Composer、Node 或 npm。已有 GitHub CLI 登录可直接使用；实际账号必须是 `crowveil`，脚本不会切换账号或改全局 Git 配置。

```bash
unzip xboard-friend-invite-0.1.0-source.zip
cd xboard-friend-invite-0.1.0
bash publish.sh --check
bash publish.sh
```

`--check` 读取远端状态，在临时目录检查源码、身份与暂存差异，不创建仓库、不提交、不推送，也不启动 Actions。正式发布会要求输入 `PUBLISH v0.1.0`。

目标固定为 `crowveil/xboard-friend-invite`。不存在时创建公开仓库；已存在的空仓库也可使用。脚本初始化 main，并设置以下**仓库本地**身份：

```text
crowveil
330225440+crowveil@users.noreply.github.com
```

自动签名在临时仓库关闭，不复用本机未知的签名密钥。提交、标签、拉取与推送地址都会检查；HTTPS 地址带或不带 `.git` 均可。不会更改原目录的 Git 历史或远端。

如果当前凭据不能创建仓库、推送工作流或触发 Actions，脚本会打印 GitHub 的错误并停止。按报错检查已有授权，不要把令牌写进源码或脚本。

## 两个工作流

| 工作流  | 何时执行                        | 作用                                                                      |
| ------- | ------------------------------- | ------------------------------------------------------------------------- |
| Tests   | main 推送或指向 main 的 PR      | 安装测试依赖，执行格式、资源、后端、DOM、发布流程及浏览器测试，并验证打包 |
| Release | 发布脚本在 Tests 成功后手动触发 | 核对仓库、操作者、提交和测试结果，构建附件并发布                          |

Release 只允许 `crowveil` 在本仓库 main 上触发；工作流使用仓库自带的 `GITHUB_TOKEN`，不需要另放个人 PAT 到 Secrets。它检查指定完整 SHA 和当前 main 一致，并且该 SHA 最近一次 Tests 已通过。

发布先创建标签与草稿，再上传、下载并核对附件的 SHA256；全部完成后才公开 Release。生成三个附件：插件 ZIP、完整源码 ZIP、`SHA256SUMS`。正式版本不覆盖附件、不移动标签、不强制推送。

## 中断与失败

- **代码推送后本地断线**：在同一份未改动的源码目录重新执行 `bash publish.sh`，会复用已推送的提交。
- **Tests 失败**：发布会停止。查看脚本打印的 Actions 链接。环境问题修复后可重跑该 Tests；需要修改源码时，先整合 main 并更新下述 `RELEASE_BASE`，再发布。
- **Release 上传中断**：重新执行同一脚本。相同标签和草稿会被复用，已上传附件比对内容后保留，缺少的附件继续上传。
- **Release 已完成，但本机没收到完成结果**：先查看 GitHub Release。脚本会拒绝再次发布同一正式版本；不要删除标签重来。Actions 中重跑同一 Release 只会验证既有附件。
- **远端 main 有其他修改**：脚本停止，避免旧源码覆盖新提交。先把更新合并到当前源码再继续。

历史失败任务会保留红色记录。判断本次结果应查看本次提交对应的 Tests 和 Release。

## RELEASE_BASE 与后续版本

首次交付的 `RELEASE_BASE` 为 `initial`，只允许空仓库，或恢复源码完全相同且只有根提交的首次发布。

后续开发先取得并整合远端 main；在开始本次修改前记录它的完整提交 SHA，写入 `RELEASE_BASE`。本文件用于检测远端变化，不能随意替换 SHA 来跳过源码整合。

```bash
gh api repos/crowveil/xboard-friend-invite/commits/main --jq .sha
```

后续正式版本更新 `FriendInvite/config.json` 的版本、README、CHANGELOG，并添加 `docs/releases/vX.Y.Z.md`。修改控制台资源后运行 `python3 tools/build.py`，再执行检查与发布。版本已公开时应递增版本号。

## 本地开发验证

本节仅供开发，不是 Debian 发布脚本的前置依赖：

```bash
composer install
npm ci
composer format:check
npm run format:check
composer test
npm test
python3 -m unittest discover -s tests -p 'test_publish.py' -v
npx playwright install --with-deps chromium
npm run test:browser
python3 tools/build.py --check --package
```

`dist/0.1.0/` 是本地构建输出，不提交仓库。最终源码 ZIP 排除 `dist/`、依赖目录、真实凭据、测试运行数据和 Git 历史。
