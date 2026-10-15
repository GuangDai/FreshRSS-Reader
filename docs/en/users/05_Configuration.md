
# Configuration

## Display

Open the settings page with the gear button in the reading toolbar. The home page uses one reading interface; its appearance is configured here.

### Theme and day/night colours

The Appearance category provides 32 palettes. Each theme selector initially shows its current choice. Select **Change theme** to browse up to six choices per page, filter light or dark palettes, or search by name and palette description. Searching, filtering and changing pages do not change the selected theme.

Choose a fixed theme, or follow the device’s light/dark setting with separate daytime and night-time themes. New accounts use Sunset; the default daytime and night-time palettes for automatic switching are Nord and Sunset.

Selecting a choice updates the local appearance preview. Expand the preview to compare the subscription sidebar, article list and article body; on a phone, switch between these three preview areas. Use **Save settings** to apply the selected appearance to the reading interface. Returning to reading before saving keeps the saved appearance; failed saves preserve the draft for retry.

### Layout, density and reading style

The layout choices are split view, list and cards. Split view places subscriptions, the article list and the open article side by side when space permits. List and cards provide alternative ways to browse articles. Narrow screens adapt to the available space without changing the saved layout preference.

Choose comfortable or compact spacing for the article list. Reading styles provide subtle surfaces, paper-like article typography with a serif font, or stronger borders and secondary text. These settings keep the selected palette’s colours.

### Article typography and widths

The Reading category controls article text size, line spacing and maximum reading width. It also provides target widths for the subscription sidebar and the article list in split view. When the viewport is narrower than the saved widths, the interface adjusts the displayed widths without overwriting those preferences.

These settings use numeric values instead of the former Fine, Medium, Large and No limit presets. The former article header/footer icon settings and separate normal, global and reader views are no longer part of the reading interface.

### Language and time zone

Open the language and time zone tool from the Appearance category. Choose the interface language and the time zone used for dates, then submit that form’s changes. Its default time zone uses the instance’s configured time zone.

Translation coverage varies by language. To contribute translations, see [contributing to internationalisation](../contributing.md#contribute-to-internationalization-i18n).

## Reading

### Number of articles per page

This setting defines the number of articles to display at once before needing to load more. In normal and reading view, more articles are loaded automatically. In global view, a button will appear at the bottom of the list.

### Articles to display

The status of articles to display when loading FreshRSS. "Adjust showing" will display only unread articles by default, but will display all articles when there are no unread articles to show.

### Use “lazy load” mode to load images

This will set images to load as they are viewed. This can save data, but will can cause images to load in later.

## Archival

### Archiving

These are the global options for fetching and retaining articles from feeds. They can be overridden by [category](./04_Subscriptions.md#archiving) settings, which can in turn be overridden by individual feed’s settings (priority order: feed, then category, then this global default).

Purging (i.e. deleting) an article is based on the date it was _discovered_ by FreshRSS, not its published date. An article still present in the upstream feed is always kept regardless of the purge policy, so FreshRSS can keep detecting new articles correctly.

Purging does not happen on every feed refresh: it is only triggered on a random subset of refreshes, to limit the load on the database. To purge immediately, use _Purge now_ below, or the `cli/purge.php` [command-line script](https://github.com/FreshRSS/FreshRSS/blob/edge/cli/README.md), which is suitable for cron.

### Maintenance

This allows for purging/optimizing the current user’s articles in the database. _Purge now_ immediately applies the archiving policy (feed, then category, then global default) described above to all of your feeds.

## Sharing

To make your life easier, you can share articles straight from FreshRSS.

At the moment, FreshRSS supports [20+ sharing services](08_sharing_services.md), ranging from self-hosted services (Shaarli, etc.) to proprietary services (Facebook, etc.).

By default, the sharing list is empty.
![Sharing configuration](../img/users/configuration.sharing.png)

To add a new item to the list, please follow the following simple steps:

1. Select the desired sharing method in the drop-down list.
2. Press the ```✚``` button to add it to the list.
3. Configure the method in the list. All  names can be modified in the display. Some methods need the sharing URL to be able to work properly (ex: Shaarli).
4. Submit your changes.

To remove an item from the list, follow those simple steps:

1. Press the ```❌``` button next to the share method you want to remove.
2. Submit your changes.


## Shortcuts

To ease the use of the application, FreshRSS comes with a lot of predefined keyboard shortcuts.
They allow actions to improve the user experience with a keyboard.

Of course, if you’re not satisfied with the key mapping, you can change you configuration to fit your needs.

There are 4 types of shortcuts:

1. Views: they allow switching views with ease.
2. Navigation: they allow navigation through articles, feeds, and categories.
3. Article actions: they allow interactions with an article, like sharing or opening it on the original web-site.
4. Other actions: they allow other interactions with the application, like opening the user queries menu or accessing the documentation.

It’s worth noting that the share article action has two levels. Once you press the shortcut, a menu containing all the share options opens.
To choose one share option, you need to select it by its number. When there is only one option, it’s selected automatically though.

The same process applies to the user queries.

Be aware that there is no validation on the selected shortcuts.
This means that if you assign a shortcut to more than one action, you’ll end up with some unexpected behavior.

## User queries

You can configure your [user queries](./user_queries.md) in that section.

## Profile

You can change your email address or password here. The authentication token is required for accessing the aggregated RSS feed for a user. A blank token will disable accessing the RSS feed without being logged in.

## Extensions

Extensions can be managed from this menu. Note that while extensions can be removed from the web interface, they cannot be added from it.

Some extensions have configurations and these can be changed in the manage page, which opens with the button near the name of the extension.

### User CSS

It gives ability to create user-specific CSS rules to apply in addition of the actual theme.

#### Example: Getting rid of Top Menu Items

The Top Menu within the mobile view might look a little bit cluttered, depending on the theme. The following CSS rules allow to hide unnecessary top menu buttons or input boxes.

```css
@media (max-width: 840px)
{
    /* Hides "Actions" Menu in Mobile View */
    #nav_menu_actions {
        display: none;
    }

    /* Hides "Views" Menu in Mobile View */
    #nav_menu_views {
        display: none;
    }

    /* Hides "Search" Input Box in Mobile View */
    .nav_menu .item.search {
        display: none;
    }

    /* Hides the Dropdown Menu Button next to the "Mark all read" Button in Mobile View */
    .mark-read-menu .dropdown {
        display: none;
    }
}
```

### User JS

It gives ability to create user-specific JS.

## Users

> **TODO**

### Authentication methods

#### HTTP Authentication (Apache)

1. User control is based on the `.htaccess` file.
2. It is best practice to place the `.htaccess` file in the `./i/` subdirectory so the API and other third party services can work.
3. If you want to limit all access to registered users only, place the file in the FreshRSS directory itself or in a parent directory. Note that [WebSub](WebSub.md) and API will not work!
4. Example `.htaccess` file for a user "marie":

```apache
AuthUserFile /home/marie/repertoire/.htpasswd
AuthGroupFile /dev/null
AuthName "Chez Marie"
AuthType Basic
Require user marie
```

More information can be found in the [Apache documentation](https://httpd.apache.org/docs/trunk/howto/auth.html#gettingitworking).
