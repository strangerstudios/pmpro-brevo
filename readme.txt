=== Paid Memberships Pro - Brevo Add On ===
Contributors: strangerstudios
Tags: pmpro, brevo, email marketing, membership, sync
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0
License: GPLv3 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Sync your PMPro members with Brevo lists.

== Description ==

This plugin integrates Paid Memberships Pro with Brevo (formerly Sendinblue) using the Brevo API. Automatically add members to Brevo lists based on their membership level.

Requires Paid Memberships Pro 3.5 or later for background processing, automatic retries of failed syncs, and the debug log. On older versions, syncs run immediately and are not retried.

Members are synced when their membership level changes or their profile is updated. There is no bulk sync, so existing members are added to Brevo the next time one of those happens.

When a WordPress user is deleted, their Brevo contact is removed from every list assigned to a membership level. The contact itself is not deleted from Brevo.

== Installation ==

1. Upload the `pmpro-brevo` directory to the `/wp-content/plugins/` directory of your site.
1. Activate the plugin through the 'Plugins' menu in WordPress.

== Frequently Asked Questions ==

= I found a bug in the plugin. =

Please post it in the issues section of GitHub and we'll fix it as soon as we can. Thanks for helping. https://github.com/strangerstudios/pmpro-brevo/issues

= What happens when I unassign a list from a level? =

Members are not removed from the list right away. Instead, the list is shown under "Previously Assigned Lists" on the settings page, and each member is removed from it the next time they sync (unless one of their levels still uses it). Check "Stop removing members from these lists" to stop managing those lists.

= What happens if I change my API key? =

If the new key belongs to the same Brevo account, nothing changes. If it belongs to a different account, or the account can't be verified, list assignments and stored Brevo contact IDs are reset, because list and contact IDs are specific to each Brevo account. Assign lists again after connecting the new account.

= My Brevo account uses different names for the first and last name attributes. =

The plugin sends FIRSTNAME and LASTNAME. Brevo ignores attributes that don't exist in your account. Use the `pmprobrevo_contact_data` filter to rename them or add other attributes.

== Hooks ==

= Filters =

* `pmprobrevo_contact_data` - Filter the contact data (email, attributes, listIds, emailBlacklisted) sent to Brevo. Params: `$contact_data`, `$user` (WP_User), `$levels` (array).
* `pmprobrevo_controlled_list_ids` - Filter the list IDs that PMPro manages. Members are only removed from lists in this set. Params: `$controlled_list_ids` (array).
* `pmprobrevo_max_sync_retries` - Filter the number of times a sync that failed with a temporary error (timeout, rate limit, or Brevo server error) is retried. Default 5. Params: `$max_retries` (int).
* `pmprobrevo_log_file_path` - Filter the debug log file path. Params: `$log_file_path` (string).

== Changelog ==

= 1.0 - TBD =
* Initial release.
