# Who should own a room's folder?

Architecture note for the B.5 arbitration. **No code implements any of this yet** —
this document exists to get a decision before anything is built, because the choice
is hard to reverse once folders have been created.

## The problem

A room's document folder is created inside a **real user's** Files area, and shared
from there with the room group. The owner is whoever first bound a folder to the
room. Consequences:

- when that person leaves the organisation and their account is deleted, the folder
  goes with it, and every member of the room loses the document space;
- when their account is merely disabled, the share survives in `oc_share` but the
  owner's storage is unavailable, so the folder resolves as deleted for everyone;
- the folder counts against that person's quota, although it holds collective data;
- the owner can move, rename or delete the folder from their own Files view without
  any indication that a room depends on it.

None of this is hypothetical for a collectivity, where staff turnover is routine.

## Options

### 1. Status quo — folders owned by their creator

**Keeps:** nothing to build, no migration, no new dependency.
**Costs:** every departure is a latent incident; quota attribution is wrong;
the trigger (account deletion) is completely outside Watcha's control.

Worth noting that the fix delivered for problem B makes this *more* visible, not
less: the panel now reports "the folder has been deleted in Nextcloud" instead of a
generic dead end, so orphaned folders will start being reported as such.

### 2. A service account owns every room folder

The Watcha service account (already configured as `watcha_service_account`, and
already the identity Synapse uses for provisioning) creates and owns the folder;
members get access purely through the room group share.

**Keeps:** ownership is independent of any person, so no departure can orphan a
room; quota is attributed to a service identity; the model barely changes — it is
still one group share on one folder, so the acceptance logic, the sync endpoint and
the file-id resolution all keep working untouched.
**Costs:** the service account's storage becomes a single point of failure and needs
its own quota and backup policy; a member browsing their own Files area no longer
sees the room folder among their own files unless they accept the share (which is
exactly what the problem A fix guarantees); migrating existing folders means moving
files between accounts, which changes file ids — and file ids are what the problem B
fix stores in room state. A migration must therefore re-resolve the binding for
every room, or accept that the stored file id becomes stale (the client re-resolves
on failure, so this degrades rather than breaks).

### 3. Group Folders

The [Group Folders](https://github.com/nextcloud/groupfolders) app provides folders
that belong to no user and are mounted for the members of a group.

**Keeps:** it is the mechanism Nextcloud actually designed for collective storage —
no owner at all, quota per folder, and no acceptance step, which would make the
whole problem A class of bug structurally impossible.
**Costs:** the largest change by far. Group folders are not `oc_share` rows, so the
connector's entire document model would be rewritten: no group share, no child
shares, no acceptance, and the file-id/path resolution changes shape. It adds an
external app to the supported set and pins another version to track. It also
removes a capability that is currently in use — a member cannot pick an existing
personal folder and share it with a room, since a group folder is created empty.

## Recommendation

**Option 2, for new rooms only, behind a setting that is off by default.**

Reasoning: it removes the failure mode that actually hurts (a departure orphaning a
room) at a fraction of option 3's cost and risk, and it composes with everything
delivered for problems A and B rather than replacing it. Restricting it to new rooms
avoids a bulk file migration entirely — the existing estate keeps working exactly as
it does today, and converges as rooms are created.

Option 3 is the better end state on paper and should be revisited if collective
storage grows into a first-class requirement (per-folder quotas, retention rules,
folders outliving rooms). It should not be attempted as a bug fix.

## What to decide

1. Do new rooms get service-account-owned folders? (recommended: yes)
2. Is the existing estate migrated, or left to converge? (recommended: left)
3. If migrated: accept that file ids change, and that the client re-resolves the
   binding on first failure — or schedule a pass that rewrites `nextcloudShare` for
   every affected room.
4. Who owns the service account's quota, backup and monitoring?

Items 1 and 2 are enough to start; 3 and 4 only matter if migration is chosen.
