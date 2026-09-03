# Sharing

A Qbix plugin: community members offer resources (a physical item, their time,
a space) and other members book them. Design and build order live in the
`ro` repo at `docs/sharing-plugin-plan.md`; state is tracked in
[zattak1/ro#474](https://github.com/zattak1/ro/issues/474).

Requires `Users`, `Streams`, `Places`, `Calendars`. Mount it into an app per
`docs/plugin-strategy.md` §6 and add `Sharing` to `PLUGINS`.

Status: slice 1 (skeleton and mount). `/sharing` renders an empty listing.
