# Reading appearance

The home page uses one reading interface with 32 built-in palettes derived from MetaCubeXD’s daisyUI configuration. Themes are application preferences, not separately installed skins. The former skin directories and their registration files are not used. Adding a directory under `p/themes/` does not add a selectable reading theme.

## Source files

- `app/Services/Reader/Preferences.php` defines valid themes, defaults and preference validation.
- `p/scripts/reader/appearance/themes.js` and `p/styles/reader/themes.css` contain the 32 palettes and their original configuration values.
- `p/scripts/reader/appearance/colours.js` derives readable interface text colours for the selected backgrounds.
- `p/scripts/reader/appearance/choices.js` implements the visual choices and local preview.
- `p/scripts/reader/articles/document.js` applies typography and colours to isolated article documents.
- `p/styles/vendor/daisyui-LICENSE` contains the licence for the copied theme configuration.

Keep palette values consistent between JavaScript, CSS and the fixed upstream snapshot in `tests/fixtures/themes/`. The application does not require a daisyUI or Tailwind runtime. Preserve the original palette values when adjusting contrast; the interface derives readable text colours separately.

## Selection and preview

Theme, daytime-theme and night-time-theme selectors initially show only the current choice. Opening the selector displays up to six choices per page, with brightness filters and search by name or palette description. Create miniature previews only for visible choices. Filtering, searching and pagination must not change the selected draft.

Layout, density and reading style have two or three visual choices displayed directly. A choice updates the local preview; the saved reading interface changes only after **Save settings** succeeds. The expanded preview shows subscriptions, article list and article body; on a phone, users switch between these areas. Preserve unsaved drafts when saving fails and keep the open article and reading position intact.

Article bodies remain isolated in an iframe without script execution or same-origin access. Appearance changes must preserve that boundary. Use logical CSS properties for direction-sensitive layout and test both left-to-right and right-to-left interfaces.

## Management and installation pages

`p/themes/base-theme/` provides the fixed base styles for management and installation pages. The loader uses this base directly, regardless of an account’s former skin preference. Shared icons and fonts remain under `p/themes/`; reading and settings styles are under `p/styles/reader/`.

Generate the base styles’ RTL counterparts with `npm run rtlcss` when changing those files. Reading styles use direction-aware rules rather than separate skin registrations.

## Checks

```sh
npm run test:frontend
npm run test:browser:appearance
npm run test:browser:reading
```

The focused appearance and reading fixtures render application templates and use local test responses; they do not require an installed account. See the [test guide](../../../../tests/README.md) for PHP and browser prerequisites, and the [user configuration guide](../../users/05_Configuration.md#display) for the settings workflow.
