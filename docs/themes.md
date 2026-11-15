# Theme images: shared artwork and lookup

Every theme lives in `public/styles/theme/<name>/` (`style.cfg`, `settings.cfg`, `theme.css` and the image folders
`planeten/`, `gebaeude/`, `achievements/`, `img/`, `images/`, `pic/`). Most artwork is the same in several themes, so
identical files are stored once in `public/styles/resource/theme-shared/`:

```
theme-shared/common/          files every theme (or at least five) shares
theme-shared/sets/<set>/      artwork of a group of themes, e.g. sets/nebula (DarkNebula, MinimalNoir, community, ...)
                              and sets/classic (nova, EpicBlueXIII, gow)
```

A theme declares the sets it can draw from in its `style.cfg`: `'assetSets' => ['nebula']` (an ordered list).

## Lookup order

Templates, PHP and JS never build theme image paths themselves; they ask for a relative path:

- Twig: `{{ theme_asset('gebaeude/' ~ id ~ '.gif') }}`
- PHP: `$THEME->asset('planeten/' . $image . '.jpg')` (or `App\Game\Core\ThemeAssets::resolve($theme, $path)`)
- JS: gets resolved URLs from the template (see `messages` and `fleetDealer`).

`App\Game\Core\ThemeAssets` returns the first hit of:

1. the theme's own folder
2. each declared set, in order
3. `common/`
4. nothing found: the theme path (a missing image shows up as a 404 like before)

Each folder is tried with the exact path first, then with the extension replaced by `.webp` (images are shipped as
WebP, so `gebaeude/229.gif` is answered by `gebaeude/229.webp`). Own files always win: a theme overrides any image by
placing a file at the same relative path in its folder; a new theme can reuse artwork by declaring a set.

The lookup map of a theme is cached in `var/game/theme-assets-<theme>.php` and rebuilt when the modification time
of one of the folders it was built from changes (file added or removed) or `style.cfg` changes. Replacing a file in
place keeps its URL; the long cache lifetime of images then needs a new file name.

`theme.css` is loaded from the css theme folder, so images it refers to with a relative `url()` have to stay in that
theme folder (or the url must point to `../../resource/theme-shared/...`). Files hard-coded as
`styles/theme/<name>/...` in the code stay too. The dedupe script respects both.

## Tools

- `tools/images/convert-theme-images.sh`: JPG/PNG/GIF to WebP (q90, animated GIFs stay animated, size limits per
  category), deletes the originals, rewrites `theme.css` references. Needs the libwebp tools (see the script header).
- `tools/images/dedupe-theme-assets.php [--apply]`: moves files that are identical across themes into
  `theme-shared/`, deletes the theme copies and verifies that every theme x path still resolves to the same bytes.
  Run both after adding or changing a theme.
