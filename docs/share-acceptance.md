# Room document space: share acceptance

Operational notes for the fix shipped in 0.8.0.

## The invariant

For a member to reach a room's document space, **two** things must hold:

1. the member belongs to the room group `<hash>_<room_id>`;
2. the group share on the room folder is **accepted** for that member.

Nextcloud materialises a group share (`oc_share.share_type = 1`) for each
recipient as a child share (`share_type = 2`, `parent = <group share id>`) that
carries its own acceptance status. Without an accepted child row nothing is
mounted, and the document space answers "folder not found".

Only (1) used to be maintained on join. (2) was handled once, when the folder was
shared, for the members present at that instant.

## What maintains the invariant now

| Path | When it runs | Scope |
| --- | --- | --- |
| `RoomGroupMembershipListener` | a member is added to a room group, whatever the cause | the joining member |
| `POST /rooms/{roomId}/members/{userId}/sync` | Synapse, after adding a member to the group on an effective join | the joining member |
| `DocumentController::createShare()` | a folder is shared with a room | all current members |
| `occ watcha:shares:accept-pending` | manually | the existing estate |

All four are idempotent and go through the public share API. None performs an
`UPDATE` on `oc_share`: that would not create the mount point and would leave the
recipient in an inconsistent state.

### Why not rely on Nextcloud's own listener

`files_sharing` ships `UserAddedToGroupListener`, which does accept pending group
shares on `UserAddedEvent`. It is not sufficient here:

- it returns early unless the recipient's `files_sharing/default_accept` user
  preference resolves to `yes` and `sharing.force_share_accept` is off — a room's
  document space must not depend on a per-user sharing preference;
- it discovers shares with `getSharedWith()`, which resolves the recipient's
  group list through a request-scoped cache that can still be stale when we are
  called microseconds after the membership was written;
- it does not materialise mount points, does not retry, and logs nothing.

Ours is scoped to room groups (`<10 hex chars>_!…`) so ordinary Nextcloud groups
keep their standard, user-controlled behaviour.

### Why there is no `UserRemovedEvent` listener

Losing group membership already revokes access: `getSharedWith()` restricts group
shares to the recipient's *current* groups, so a lingering child row grants
nothing. Keeping it is in fact desirable — a member removed and re-added recovers
their folder, and their own mount name, immediately. Deleting shares on a
membership event would also be destructive in exactly the situation this fix
exists for: a flaky sync.

## Remediation of an existing estate

Nothing is repaired retroactively at install time, by design. Use:

```bash
occ watcha:shares:accept-pending --dry-run
```

to size the work, then apply per room, or wholesale:

```bash
occ watcha:shares:accept-pending --room='!abc:example.org'
```

The command asks for confirmation; in a non-interactive shell it refuses to write
unless `--force` is given. Exit code is non-zero if anything remained pending.

To audit independently, read-only:

```sql
select s.id, s.share_with as room_group, gu.uid
from oc_share s
join oc_group_user gu on gu.gid = s.share_with
where s.share_type = 1
  and not exists (select 1 from oc_share c
                  where c.parent = s.id and c.share_with = gu.uid and c.accepted = 1);
```

## Folder resolution by file id

The client no longer locates the folder by name. `GET /rooms/{roomId}/folder`
returns the Nextcloud file id — stable — plus the path as currently mounted for the
calling user, and a status (`ok`, `pending`, `not-member`, `deleted`, `no-share`).

Resolution uses `IShare::getNodeId()` and `IShare::getTarget()`, so there is no
WebDAV round trip: the per-recipient mount path is already what `getTarget()`
returns for a child share.

Access goes through Synapse (`GET /_watcha/nextcloud/rooms/{roomId}/folder`), for
two reasons: every route of this app is restricted to the service account by
`SecurityMiddleware`, and Synapse is the only party that can authorise the request
properly — it verifies that the caller is actually a member of the room before
resolving anything. Without that check any user could probe any room's folder.

The room setting that binds a room to a folder keeps its existing shape — a
Nextcloud Files URL — with the file id added as a query parameter. It is
deliberately *not* promoted to a richer object: the value lives in room state and is
read by clients of every vintage, which do `new URL(value)` and would throw on
anything else, whereas an unknown query parameter is simply ignored. Rooms migrate
themselves the first time a client resolves them, so there is nothing to migrate by
hand.

## Deployment

| Item | Needed? |
| --- | --- |
| Schema migration | **No.** No table or column is added or altered. |
| Service restart | **No** for Nextcloud (PHP is loaded per request). **Yes** for Synapse, which is a long-lived process. |
| Cache invalidation | Yes — `occ maintenance:repair` is not required, but the app must be re-enabled so the new event listener and the new routes are registered: `occ app:disable watcha && occ app:enable watcha`. Clear the route cache if one is configured. |
| Config change | None required. `room_group_prefix` is optional. |
| Order | Deploy the Nextcloud app **before** Synapse: Synapse's new calls need the new routes to exist. Until they do, the calls fail, are logged, and the listener still covers joins — so a partial rollout degrades rather than breaks. Element may be deployed at any point: it falls back to the stored path when resolution is unavailable. |

## Rollback

Both halves roll back independently and in either order, because each is
individually sufficient for new joins.

**Nextcloud app** — reinstall the previous version and re-enable it:

```bash
occ app:disable watcha
# restore the previous app directory
occ app:enable watcha
```

No data migration is undone: acceptance produces ordinary accepted child shares,
which the previous version reads normally. Members repaired before the rollback
keep their access. New late joiners revert to being broken.

**Synapse** — redeploy the previous build and restart. The only externally
visible change is that `sync_room_member` stops being called; group membership
sync behaves as before.

**Element** — redeploy the previous build. Rooms whose setting has already been
rewritten with a file id keep working: the previous client reads `dir` from the same
value, which was preserved precisely for this. This is the reason the setting was not
promoted to a richer object.

One behaviour change is *not* restored by rolling back the connector alone, and
is worth knowing about: Synapse now evaluates the partner guard on the
**target** of a membership change rather than on the requester. Rolling back
Synapse restores the previous behaviour, in which a member who accepted their own
invitation could be skipped entirely.
