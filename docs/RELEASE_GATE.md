# EverShelf: Manual release gate for v1.16.27

Default policy: **no automatic releases** on pushes to main, develop, tags
or pull requests. Ordinary CI, Android build-only checks, translations and
security scans continue to run.

## Explicitly authorized release entry points

Select branch **main** in GitHub Actions and manually run one workflow with
the exact confirmation input. The publish job otherwise stays skipped.

| Workflow | Exact confirmation |
| --- | --- |
| Publish Docker Image | PUBLISH-DOCKER |
| Build & Release Kiosk APK | PUBLISH-KIOSK |
| Build & Release Health Bridge APK | PUBLISH-HEALTH-BRIDGE |
| Deprecated Scale Gateway APK | PUBLISH-LEGACY-SCALE |

The Docker latest tag has an additional checkbox, initially off.
Kiosk/Health Bridge manual releases still require valid signing settings.
A manual release is a deliberate, separate operation after a release
approval, not part of merging or running CI.

## What changed

- The former CI auto-merge from develop into main is removed.
- CI no longer creates version tags, releases or calls Docker publishing.
- Docker workflow no longer responds to pushes, tags or reusable calls.
- Kiosk and Health Bridge workflows no longer publish after main pushes.
- Deprecated Scale Gateway accepts a meaningful confirmation only on main.
- The release gate regression test checks trigger/condition source and
  rejects deliberately mutated unsafe versions.

## Remaining release blockers

**This change does not protect main until these workflow files have been
reviewed and explicitly landed on main.** While main still contains the
old workflows, the old automatic publishing behavior remains active.
Do NOT merge this draft or RC into main without a separately approved and
checked deployment sequence.

GitHub branch protection or repository rulesets must be configured
separately (required PR reviews/status checks and blocked direct pushes).
For stronger controls, set up a protected release Environment requiring
an independent reviewer and attach it to the publishing jobs. The manual
confirmation strings are *not* security credentials or reviewer approval.

No real release, OTA update, production restore or staging deployment is
carried out by this change. See EverShelf Issue #20 and RC PR #21.
