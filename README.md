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
