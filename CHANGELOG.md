# Changelog

## 0.8.0

### Fixed

- A room's document space was located by **folder name**, which is not stable. Each
  recipient of a Nextcloud share may rename their own mount
  (`oc_share.file_target` on the child share) and Nextcloud appends a suffix on
  collision, so the same folder is `/Nouveau dossier` for its owner and
  `/FACILITATEURS` for a member who renamed it — and the name resolved for one
  member produced a 404 for the next.

  New `GET /rooms/{roomId}/folder` route returns the Nextcloud **file id**, which
  is stable, together with the path as currently mounted for the calling user and
  a status the client can act on (`ok`, `pending`, `not-member`, `deleted`,
  `no-share`). Resolution uses `IShare::getNodeId()` and `getTarget()`, so no
  WebDAV round trip is involved on either side.

- A room's document space became unreachable ("folder not found") for any member
  who joined **after** the folder was shared with the room. The share stayed
  pending for them, so Nextcloud mounted nothing. The only workaround was to
  remove and re-invite the member, which recreated the share and re-accepted it
  for the whole group.

  Share acceptance now happens whenever a member joins a room group, not only
  when the folder is shared:
  - new `OCA\Watcha\Listener\RoomGroupMembershipListener`, on
    `OCP\Group\Events\UserAddedEvent`;
  - new `POST /rooms/{roomId}/members/{userId}/sync` route, called by Synapse
    after it adds a member to the group, which guarantees group membership *and*
    share acceptance in one idempotent operation and returns a verifiable report;
  - acceptance logic extracted from `DocumentController::createShare()` into
    `OCA\Watcha\Service\ShareAcceptanceService`, now the single implementation.

  Nextcloud's own `files_sharing` listener covers the generic case, but it is
  discretionary (it obeys the recipient's `default_accept` preference and
  `sharing.force_share_accept`) and it discovers shares through a
  request-scoped group cache that is still stale when the membership was just
  written. A room's document space is not an optional personal share, so
  acceptance for room groups is now unconditional. Both listeners running is
  harmless — acceptance is idempotent.

### Added

- `occ watcha:shares:accept-pending [--room=<room_id>] [--dry-run] [--force]` to
  replay acceptance over an existing estate, with per-room detail and counters.
  It refuses to write in a non-interactive shell unless `--force` is given.
- `watcha` app config key `room_group_prefix`, for deployments whose room group
  naming prefix differs from the default.

### Changed

- Room group naming is now defined once, in `OCA\Watcha\RoomGroup`, instead of
  being duplicated between the calendar controller and Synapse's convention.
- Every Nextcloud write performed during acceptance is retried with exponential
  backoff. On SQLite-backed deployments a single writer lock makes these
  failures intermittent, and they previously surfaced as a permanently missing
  document space.
- Failures are logged per user (`app=watcha`) instead of being swallowed, and one
  failing member no longer aborts the batch.

### Deprecations

- `@NoAdminRequired` / `@NoCSRFRequired` docblock annotations replaced by the
  `OCP\AppFramework\Http\Attribute\*` attributes, and the deprecated `UserId`
  DI alias replaced by `userId`. Both were emitting deprecation warnings on
  every request.
