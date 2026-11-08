=== User Role Editor ===
Contributors: shinephp
Tags: user, role, editor, security, access
Requires at least: 4.7
Tested up to: 7.2
Stable tag: 4.67
Requires PHP: 8.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

User Role Editor WordPress plugin makes user roles and capabilities changing easy. Edit/add/delete WordPress user roles and capabilities.

== Description ==

User Role Editor WordPress plugin allows you to change user roles and capabilities easy.
Just turn on check boxes of capabilities you wish to add to the selected role and click "Update" button to save your changes. That's done. 
Add new roles and customize its capabilities according to your needs, from scratch of as a copy of other existing role. 
Unnecessary self-made role can be deleted if there are no users whom such role is assigned.
Role assigned every new created user by default may be changed too.
Capabilities could be assigned on per user basis. Multiple roles could be assigned to user simultaneously.
You can add new capabilities and remove unnecessary capabilities which could be left from uninstalled plugins.
Multi-site support is provided.

To read more about 'User Role Editor' visit [this page](https://role-editor.com/documentation/general-features-free-version/)


Do you need more functionality with quality support in a real time? Do you wish to remove advertisements from User Role Editor pages? 
[Buy Pro version](https://www.role-editor.com). 
[User Role Editor Pro](https://www.role-editor.com) includes extra modules:
<ul>
<li>Block selected admin menu items for role.</li>
<li>Hide selected front-end menu items for no logged-in visitors, logged-in users, roles.</li>
<li>Block selected widgets under "Appearance" menu for role.</li>
<li>Show widgets at front-end for selected roles.</li>
<li>Block selected meta boxes (dashboard, posts, pages, custom post types) for role.</li>
<li>"Export/Import" module. You can export user role to the local file and import it to any WordPress site or other sites of the multi-site WordPress network.</li> 
<li>Roles and Users permissions management via Network Admin  for multisite configuration. One click Synchronization to the whole network.</li>
<li>"Other roles access" module allows to define which other roles user with current role may see at WordPress: dropdown menus, e.g assign role to user editing user profile, etc.</li>
<li>Manage user access to editing posts/pages/custom post type using posts/pages, authors, taxonomies ID list.</li>
<li>Per plugin users access management for plugins activate/deactivate operations.</li>
<li>Per form users access management for Gravity Forms plugin.</li>
<li>Shortcode to show enclosed content to the users with selected roles only.</li>
<li>Posts and pages view restrictions for selected roles.</li>
<li>Admin back-end pages permissions viewer</li>
</ul>
Pro version is advertisement free. Premium support is included.

== Installation ==

Installation procedure:

1. Deactivate plugin if you have the previous version installed.
2. Extract "user-role-editor.zip" archive content to the "/wp-content/plugins/user-role-editor" directory.
3. Activate "User Role Editor" plugin via 'Plugins' menu in WordPress admin menu. 
4. Go to the "Users"-"User Role Editor" menu item and change your WordPress standard roles capabilities according to your needs.

== Frequently Asked Questions ==
- Does it work with WordPress in multi-site environment?
Yes, it works with WordPress multi-site. By default plugin works for every blog from your multi-site network as for locally installed blog.
To update selected role globally for the Network you should turn on the "Apply to All Sites" checkbox. You should have superadmin privileges to use User Role Editor under WordPress multi-site.
Pro version allows to manage roles of the whole network from the Netwok Admin.

== Screenshots ==
1. screenshot-1.png User Role Editor main form
2. screenshot-2.png Add/Remove roles or capabilities
3. screenshot-3.png User Capabilities link
4. screenshot-4.png User Capabilities Editor
5. screenshot-5.png Bulk change role for users without roles
6. screenshot-6.png Assign multiple roles to the selected users

To read more about 'User Role Editor' visit [this page](https://role-editor.com/documentation/) at [https://role-editor.com](role-editor.com).

= Translations =

If you wish to check available translations or help with plugin translation to your language visit [this link](https://translate.wordpress.org/projects/wp-plugins/user-role-editor/)


== Changelog =

= [4.67] 04.11.2026 =
* Security fix: Users page: Change Role, Grant Role, Add Role: a user with the "promote_users" capability could grant themselves (or others) roles with capabilities beyond their own, when custom high-privilege roles existed on the site. Role assignment now requires that the acting user already holds all critical capabilities of the target role; self-assignment of roles is no longer allowed. Thanks to Ahmed Mahmoud (Haktrak Networks) for the responsible disclosure.
* New: custom filter 'ure_get_critical_capabilities' allows to modify the list of critical capabilities used for the role assignment check. 'manage_options', 'activate_plugins', 'delete_plugins', 'edit_users', 'delete_users' capabilities are under control by default.
* Fix: "Without role" button on the Users page stopped working (showed "Please select at least one item to perform this action on." after role selection). 
* Update: allow users with only the 'promote_users' capability to use the "Without role" button.

= [4.66.3] 26.10.2026 =
* Update: Marked as compatible with WordPress version 7.2
* Update: Required PHP version increased up to 8.2
* Fix: Multisite: only the first 100 sites of a network were processed when a role change was applied to all sites of the network and when plugin data was cleaned up on uninstall, as get_sites() (used by URE_Base_Lib::get_blog_ids() since version 4.66) returns 100 sites max by default.
* New: 'ure_get_blog_ids_args' filter allows to exclude archived, spam and deleted sites from the list of network sites processed by the plugin (e.g. when a role change is applied to all sites of the network). Return an array with any of 'archived', 'spam', 'deleted' keys set to 0 (exclude such sites) or 1 (process such sites only), e.g. array( 'archived' => 0, 'spam' => 0, 'deleted' => 0 ). Other get_sites() arguments are ignored. Plugin data cleanup on uninstall always processes all sites.

File changelog.txt contains the full list of changes.

== Additional Documentation ==

You can find more information about "User Role Editor" plugin at [this page](https://role-editor.com/documentation/)

== Upgrade Notice ==

= [4.67] 04.11.2026 =
* Security fix: Sites with new custom and modified user roles could be affected. Users page: Change Role, Grant Role, Add Role: a user with the "promote_users" capability could grant themselves (or others) roles with capabilities beyond their own, when custom high-privilege roles existed on the site. Role assignment now requires that the acting user already holds all critical capabilities of the target role; self-assignment of roles is no longer allowed. Thanks to Ahmed Mahmoud (Haktrak Networks) for the responsible disclosure.

