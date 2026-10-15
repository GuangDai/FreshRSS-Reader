# Reading appearance

Users choose from 32 built-in palettes in **Settings → Appearance**. Each selector shows the current choice until **Change theme** is opened, then displays up to six choices per page with light/dark filters and search. A choice updates the local preview; **Save settings** applies it to the reading interface. Automatic mode uses separate daytime and night-time themes.

The reading interface is the default home page. It does not load the former selectable skins from `p/themes/`, and copying another skin directory there does not add a reading theme. `p/themes/base-theme/` supplies the fixed base styles for management and installation pages; an account’s old skin preference does not select a different stylesheet.

Reading palettes and typography are part of the application source. For their locations and checks, see [appearance development](../developers/04_Frontend/02_Design.md). Back up user data and configuration before updating, as described in the [backup guide](05_Backup.md).
