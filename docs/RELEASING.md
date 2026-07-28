# Releasing ColdAisle

## What we track

Every user-facing or ops-facing change goes into [`CHANGELOG.md`](../CHANGELOG.md) under **[Unreleased]**, in one of:

| Category | Use for |
|----------|---------|
| **New features** | Capabilities that did not exist before |
| **Enhancements** | Improvements to existing behavior/UI/docs |
| **Bug fixes** | Incorrect behavior fixed |

Do **not** list pure chores (typos in comments, local debug) unless they matter to operators.

## During development

After finishing a change (or as part of the PR/commit), add a bullet:

```markdown
## [Unreleased]

### New features

- Short description of what operators/users gain.

### Enhancements

### Bug fixes
```

Keep bullets concise; start with a capital letter; no trailing period required. Mention UI path when useful (`Settings → Updates`, `Power → PDU Templates`).

## Shipping a version

Prefer the release script (elevated not required; needs git push rights):

```powershell
cd C:\path\to\ColdAisle
# Example: ship 0.2.76 with notes currently under [Unreleased]
.\scripts\Release-ColdAisle.ps1 -Version 0.2.76
```

What the script does:

1. Reads `CHANGELOG.md` **[Unreleased]** sections  
2. Fails if all three categories are empty (override with `-AllowEmpty`)  
3. Writes a new `## [x.y.z] - YYYY-MM-DD` block and clears Unreleased  
4. Sets `VERSION` and `App::VERSION`  
5. Commits, creates annotated tag `vX.Y.Z`, pushes `main` + tag  
6. Creates a **GitHub Release** with the same categorized body when `gh` is available and authenticated (`gh auth status`); otherwise prints the notes for manual paste  

### Manual steps (if not using the script)

1. Move Unreleased bullets into `## [x.y.z] - date`  
2. Bump `VERSION` and `src/App.php`  
3. Commit: `Release x.y.z: short summary`  
4. `git tag -a vX.Y.Z -m "…"`  
5. `git push origin main` and `git push origin vX.Y.Z`  
6. Create GitHub Release from the tag; body = that version’s CHANGELOG section  

## In-app updates

The app’s **Settings → Updates** uses GitHub **tags** (zipballs). Publishing a GitHub Release with notes does not change the update mechanism, but operators and the repo homepage benefit from consistent notes. Link: [CHANGELOG.md](../CHANGELOG.md) on the default branch.

## Tips

- One release can mix all three categories.  
- Omit empty category headings when promoting Unreleased (the script drops empty sections).  
- Security-sensitive items: describe impact without exploit detail.  
- Backlog-only ideas stay in [`BACKLOG.md`](../BACKLOG.md), not the changelog, until shipped.
