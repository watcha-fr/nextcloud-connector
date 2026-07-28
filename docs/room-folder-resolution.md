# Resolving a room's document folder

Operational notes for the folder resolution shipped in 0.8.0.

## The problem it fixes

The client located the folder by **name**, building a URL from the folder label.
A Nextcloud mount name is not stable: every recipient of a share may rename their
own mount (`oc_share.file_target` on the child share), and Nextcloud appends a
suffix on collision.

Measured on the affected deployment, for the same folder (parent share `59`,
actually named `/Nouveau dossier`):

| Mount name | Users |
| --- | --- |
| `/Nouveau dossier` (canonical) | 24 |
| `/Facilitateurs` | 1 |
| `/FACILITATEURS` | 1 |

Hence `PROPFIND …/FACILITATEURS/ → 404`. Estate-wide, **26 child shares** have a
`file_target` differing from their parent.

## What replaces it

`GET /rooms/{roomId}/folder?requester=<uid>` returns the Nextcloud **file id**,
which is stable, plus the path as currently mounted **for that user**, and a
status. It uses `IShare::getNodeId()` and `IShare::getTarget()`, so there is no
WebDAV round trip on either side.

Access goes through Synapse (`GET /_watcha/nextcloud/rooms/{roomId}/folder`) for
two reasons: every route of this app is restricted to the service account by
`SecurityMiddleware`, and Synapse is the only party that can authorise the
request properly — it verifies the caller is a member of the room. Without that,
any user could probe any room's folder.

## What a missing child share does *not* mean

Access is carried by the **parent group share**. Nextcloud materialises a
per-recipient child row (`share_type = 2`) only **lazily** — when the recipient
renames, moves or rejects their mount. Its absence is the normal state and says
nothing about access.

This matters because it is easy to mistake for a defect. The ratio of
"member with no accepted child row" per share is **0.55** on the deployment that
was reported as broken and **0.50** on one that works: no anomaly. A fix was once
built on that misreading; `IShare::STATUS_PENDING` is therefore treated as
reachable here, deliberately.

Only `STATUS_REJECTED` denies access: the recipient dismissed the share and
Nextcloud removed their mount. That is the one per-recipient state the client
reports, and it is repaired from Nextcloud, not from Watcha.

Statuses: `ok`, `rejected`, `not-member`, `deleted`, `no-share`.

## The stored setting

The room setting binding a room to a folder keeps its existing shape — a Nextcloud
Files URL — with the file id added as a query parameter, and `dir` preserved.

It is deliberately **not** promoted to a structured object: the value lives in
room state and is read by clients of every vintage, which do `new URL(value)` and
would throw on anything else, whereas an unknown query parameter is ignored.
Keeping `dir` is what makes an Element rollback harmless.

Rooms migrate themselves the first time a client resolves them, so there is
nothing to migrate by hand.

## Deployment

| Item | Needed? |
| --- | --- |
| Schema migration | **No.** |
| Service restart | **No** for Nextcloud. **Yes** for Synapse. |
| Cache invalidation | Yes — re-enable the app so the new route is registered: `occ app:disable watcha && occ app:enable watcha`. |
| Config change | None. `room_group_prefix` is optional. |
| Order | Nextcloud app **before** Synapse. Element may go at any point: it falls back to the stored path when resolution is unavailable. |

## Rollback

**Nextcloud app** — restore the previous directory, then `occ app:disable watcha`
and `occ app:enable watcha`. Nothing to undo: resolution only reads.

**Element** — redeploy the previous build. Rooms whose setting already carries a
file id keep working, because `dir` was preserved in the same value.

## Audit queries (read-only)

Diverging mount names — useful to pick a test room:

```sql
select c.parent as parent_share, p.file_target as canonical_name,
       c.share_with as recipient, c.file_target as name_seen_by_recipient
from oc_share c
join oc_share p on p.id = c.parent
where c.share_type = 2
  and c.file_target <> p.file_target;
```

Explicitly rejected shares — the only per-recipient denial:

```sql
select parent, share_with, file_target
from oc_share
where share_type = 2 and accepted = 2;
```
