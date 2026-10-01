# Sharing

A Qbix plugin: community members post listings in either direction -- an offer
(a physical item; later their time or a space) or a need -- and other members
respond with engagements that the listing publisher accepts. Design and build order live in the
`ro` repo at `docs/sharing-plugin-plan.md`; state is tracked in
[zattak1/ro#474](https://github.com/zattak1/ro/issues/474).

Requires `Users`, `Streams`, `Places`, `Calendars`. Mount it into an app per
`docs/plugin-strategy.md` §6 and add `Sharing` to `PLUGINS`.

Status: v1 code complete (2026-09-06) — post offers and needs, respond,
accept/reject, the custody and non-custody lifecycles, cancel, and the
close-listing guard, with private handoff data reaching only accepted
responders.

Deployed 2026-09-08: v1 (0.2) is live on yoga.reallyours.com and
demo.reallyours.com, yoga being its first customer.

Engagement events notify the counterparty through a per-user `Sharing/notices`
stream (`classes/Sharing/Notice.php`): a lifecycle message on the engagement is
the audit trail, and the notice is what Streams actually delivers, since
delivery runs off `Streams_Subscription` and nobody subscribes to an engagement
([ro#769](https://github.com/zattak1/ro/issues/769)). Wiring — the notice
stream type, its subject keys and its delivery rule — is pinned by
`infra/tests-php/tests/Unit/SharingNoticeConfigTest.php` in the `ro` repo,
which mounts a checkout of this repository found beside the main one.

The plugin's own methods are the only write path to its streams
([ro#586](https://github.com/zattak1/ro/issues/586)): `Sharing_Guard`
(`classes/Sharing/Guard.php`) sits on the Streams save, relate/unrelate, post
and close events for every Sharing type and refuses any write made outside
`Sharing::asServer()`, because several generic Streams handlers write streams
without reading a type's `create`/`edit`/`post` config. Every decision that
reads engagement state -- propose, each transition, pause, close -- runs under
the listing's row lock (`Sharing_Listing::locked()`) against state re-read
under it. Messages posted under that lock are written in its transaction,
but their node call (socket broadcast, delivery) is held and made only after
the COMMIT, and dropped on a rollback
([ro#863](https://github.com/zattak1/ro/issues/863)). So is every other
node call made under the lock, such as the `Streams/Stream/create` announcing
a new engagement: the lock switches `Q/nodeInternal` off while it is held
([ro#868](https://github.com/zattak1/ro/issues/868)). Pause/resume/close go through `PUT Q/plugins/Sharing/listing`.
`text/Sharing/content/en.json` must stay strict JSON: the browser parses it.
Tests: `infra/tests-php/tests/{Unit,Integration}/Sharing*` and
`infra/tests/smoke/yoga.flows.sharing.spec.ts` in the `ro` repo.
