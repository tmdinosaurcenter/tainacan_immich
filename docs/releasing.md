# Releasing

How to publish a new plugin version. For maintainers.

1. Set the new version `X.Y.Z` (semantic versioning) in the four places: `Version:` and `Stable tag:` in `tainacan-immich/tainacan-immich.php`, `Stable tag:` in `tainacan-immich/README.txt`, `version` in `tainacan-immich/package.json`. Add a `= X.Y.Z =` entry with `* ` lines to `== Changelog ==` in README.txt. Sync `package-lock.json` (`npm install` in `tainacan-immich/`). Commit.
2. `git tag vX.Y.Z`, then push the branch and the tag to Gitea (`git push origin main vX.Y.Z`). The Gitea push mirror forwards both to GitHub.
3. On GitHub, the "Release" workflow job `build` checks the notes, runs lint, tests and the build, runs `bin/package.sh vX.Y.Z` and keeps the zip as an artifact. Then job `release` (the only job with write access, no npm) publishes the release with `tainacan-immich.zip`. If the release exists, it replaces the asset and the notes.
4. Dry run: start "Release" by hand (Actions tab, "Run workflow"). It does not publish. It keeps the zip as a workflow artifact (`tainacan-immich-zip`) for 7 days.

This needs:

- Actions enabled for the GitHub repo.
- Workflow permissions that allow `contents: write` for jobs that ask for it.
- A Gitea mirror that pushes tags, not only branches.

By hand (fallback):

```
cd tainacan-immich && npm ci && npm run build && cd ..
bin/package.sh vX.Y.Z
bin/changelog-notes.sh vX.Y.Z > /tmp/notes.md
gh release create vX.Y.Z build/tainacan-immich.zip --title vX.Y.Z --notes-file /tmp/notes.md
```

Rules:

- `bin/package.sh` is the only way to make the zip.
- When you update an action, update `.github/action-pins.txt` and both workflow files (`release.yml`, `ci.yml`) in the same commit. `tests/test-workflows.php` fails if they differ. The user creates and edits `.github/action-pins.txt` by hand (agents must not write it).
- The asset name must stay `tainacan-immich.zip`.
- The zip top folder must be `tainacan-immich/`.
- Tags are `vX.Y.Z`.
- The release notes become the "View details" changelog (plain lines and `* ` bullets).
