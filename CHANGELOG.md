# Changelog

## 0.8.0

### Fixed

- A room's document space was located by **folder name**, which is not stable.
  Each recipient of a Nextcloud share may rename their own mount
  (`oc_share.file_target` on the child share) and Nextcloud appends a suffix on
  collision, so the same folder is `/Nouveau dossier` for 24 members,
  `/Facilitateurs` for one and `/FACILITATEURS` for another — and the name
  resolved for one member produced a 404 for the next.

  New `GET /rooms/{roomId}/folder` route returns the Nextcloud **file id**, which
  is stable, together with the path as currently mounted for the calling user and
  a status the client can act on (`ok`, `rejected`, `not-member`, `deleted`,
  `no-share`). Resolution uses `IShare::getNodeId()` and `getTarget()`, so no
  WebDAV round trip is involved on either side.

  See [docs/room-folder-resolution.md](docs/room-folder-resolution.md).

### Changed

- Room group naming is now defined once, in `OCA\Watcha\RoomGroup`, instead of
  being duplicated between the calendar controller and Synapse's convention.

### Deprecations

- `@NoAdminRequired` / `@NoCSRFRequired` docblock annotations replaced by the
  `OCP\AppFramework\Http\Attribute\*` attributes, and the deprecated `UserId` DI
  alias replaced by `userId`. Both were emitting deprecation warnings on every
  request.

### Not shipped, and why

An earlier iteration of this release added a share-acceptance service, a
`UserAddedEvent` listener, an `occ watcha:shares:accept-pending` command and a
member-sync route, on the assumption that members joining a room after its folder
was shared were left with an unaccepted share. **That diagnosis was wrong and the
work has been reverted.**

Two findings retired it:

- an instance where invitations work runs the *same* app version, with no
  membership listener and the same acceptance limited to `createShare` — so this
  code could not explain a difference in behaviour between deployments;
- the metric behind the diagnosis does not measure access. Nextcloud creates
  per-recipient child shares **lazily**, only when a recipient renames, moves or
  rejects their mount; their absence is the normal state, since access is carried
  by the parent group share. The ratio was 0.55 unaccepted pairs per share on the
  deployment reported as broken versus 0.50 on a healthy one.

The one per-recipient state that really denies access is an explicit
`STATUS_REJECTED`, which the resolver above reports. The real cause of the
reported symptom is in Synapse, not here.
