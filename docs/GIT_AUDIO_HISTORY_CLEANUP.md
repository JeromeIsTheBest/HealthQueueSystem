# Reviewable plan for historical audio cleanup

Current working-tree protection is already in place: `/storage/` is ignored by Git and blocked by Apache. Audio files in the current project are not added to new commits. This does not remove older objects.

Confirmed historical source: commit `4a453c1c` contains three paths under `storage/audio/`. Commit `e141141f` removes them from the current tree, but their earlier versions remain reachable. Do not copy the recordings into this document, reports, or public artifacts.

## Controlled execution plan

1. Coordinate a push freeze with every collaborator. First commit/review current authorized source changes separately; do not run a history rewrite against this dirty working directory.
2. Save a protected, local recovery mirror outside the published repository. Treat it as sensitive because it contains the audio. Define a restricted retention period and dispose of it after verification.
3. Make a second isolated mirror for the rewrite. Remove the entire `storage/audio/` path from every branch and tag using a history-rewriting tool, preserving the application source and current ignore rules. Check for renamed/copied versions of the recordings as well; a single-path filter cannot guarantee those copies are absent.
4. Review the resulting commit/ref mapping and confirm that no reachable commit contains the removed paths or original audio blob IDs. Verify that the latest application tree is unchanged apart from the intended removals. Run repository integrity checks and the application regression suite.
5. Present the proposed old/new branch and tag tips for approval. Publishing rewritten history replaces commit IDs and can disrupt collaborators; do not force-push as part of ordinary feature updates.
6. After approval, publish the reviewed branch/tag replacements during the agreed freeze. Have collaborators re-clone or carefully reset to the rewritten history, and avoid merging old branches that would restore the objects.
7. Review GitHub pull-request refs, forks, cached views and existing clones. A local rewrite alone does not guarantee removal from those locations; follow the hosting provider's sensitive-data removal process where needed.

Read-only inventory commands:

```powershell
git log --all --oneline -- storage/audio/
git ls-tree -r --name-only 4a453c1c -- storage/audio/
git ls-files -- storage/
```

Status: plan prepared; no local or remote history rewrite or force-push performed. Existing local clinical files are preserved. Repository cleanup cannot erase copies already downloaded elsewhere.
