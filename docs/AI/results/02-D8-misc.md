# 02 D8 Misc - results

Status: IN-PROGRESS (B3 started; spot-checked, not yet exhaustive).

## Entry points

- `AnalyticsEventController`, `ContactMessageController`, `InspiredLeadController`,
  `SettingController` (admin), `Admin/UserController`, `DashboardController`,
  `AdminAnalyticsController`.

## Verified OK

- **Admin user management "can't demote/delete the last admin or yourself"**: not applicable —
  `Api/V1/Admin/UserController` only exposes `index`, `show`, `wishlist`, `addresses`
  (read-only views of a customer). There is no route or controller method anywhere that updates a
  user's role, disables/deletes a user, or otherwise mutates an account
  (`grep` of `routes/api.php` for `AdminUserController` shows only 4 `GET` routes). There is
  nothing to protect because the feature doesn't exist; building admin role/user management is a
  new feature and out of scope for this pass.
- **Settings caching**: `Setting::getValue()`/`setValue()` (`app/Models/Setting.php`) read/write
  the DB directly with no `Cache::remember` layer at all, so "cache invalidated on update" doesn't
  apply — every read is already fresh, there is no staleness window to introduce a bug into.

## Findings

None found in the areas spot-checked this pass. Full coverage of public write-endpoint throttling,
the 3-metrics dashboard-accuracy check, and the dead-code report (`Campaign`, `Post`, …) was not
completed in this batch and is carried forward.

## Tests added/strengthened

None yet.

## Frontend impact

None identified.
