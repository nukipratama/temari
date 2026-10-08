## PR handoff

Every pull request is a reviewer handoff, not just a change list. Keep the description aligned with the issue and include:

- the user-visible outcome and the settled decision or acceptance criteria it implements;
- a concise map of the affected files/subsystems, including migrations, jobs, queues, backfills, or external-service effects;
- exact verification commands and their results, plus any checks that could not run;
- a reviewer path: fixtures, flags, routes, screenshots, or focused tests that make the behavior easy to reproduce; for a visual change, `gh pr edit <n> --attach '<file>#<alt>'` can upload the reviewed screenshots without a browser;
- for a UI change, the matrix of touched surfaces verified on both grounds at a ≥1280px viewport;
- for a change with motion or interaction (transitions, popovers, gestures, loading states), a short real-speed before/after clip on both grounds beside the screenshots, recorded per the `browser-review` skill's clips recipe;
- rollout, privacy, failure, rollback, and follow-up notes, including demo-data exclusions where relevant.

Use `Closes #<n>` in the PR body, keep issue/PR text free of secrets and identifying athlete data, and update the description when later pushes change scope or verification. The repository is public: describe athlete ids, emails, hostnames and per-athlete costs rather than pasting them.

**Drafting.** Draft the body in `.planning/pr-<n>.md`, change it with Edit, and send it with `gh pr create|edit --body-file`; never patch a body in place with perl or sed.

**CI.** A check blocks merging only when its job is in `ci.yml` under `ci-gate`'s `needs`; a standalone workflow is advisory.
