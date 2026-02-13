## ThemeDemo

### Usage

1. Open Typecho admin.
2. Enable the `ThemeDemo` plugin.
3. Optional: allow visitor preview in plugin config.

### Preview

- Switch and persist preview theme: `?theme=theme_dir`
- Clear preview state: `?theme=clear`

### Behavior

- After visiting `?theme=theme_dir` once, the plugin stores the theme in a cookie.
- Following archive requests (post page, category page, index page) continue using the preview theme style, even without the `theme` query parameter.
- Visiting `?theme=clear` removes the cookie and restores the site default theme style.

