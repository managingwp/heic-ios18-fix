# Translations

The plugin ships no compiled translations yet. Generate the template from the
plugin root with WP-CLI:

```bash
wp i18n make-pot . languages/heic-fix-monitor.pot --domain=heic-fix-monitor --exclude=tests,docs,bin,artifacts
```

Add translator comments above every string with placeholders. Keep all
user-facing text inside the `heic-fix-monitor` text domain.
