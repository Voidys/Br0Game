# Templates and page rendering

How game pages are rendered and escaped. Applies to the four game areas (public pages, the game,
the account lobby, the admin panel) and the installer.

## Where things live

| What | Where |
| --- | --- |
| Templates | `templates/{login,game,account,admin,install}/` (plain paths, e.g. `game/page.overview.default.html.twig`) |
| Page rendering for controllers | `src/Controller/RendersPages.php` (trait of every area base class) |
| Area base classes | `src/Controller/{Login,Game,Account}/Abstract*Controller.php`, `src/Controller/Admin/AbstractAdminController.php` |
| Admin layout data | `src/Controller/Admin/AdminShell.php` (`pageData()`) |
| Renderer outside controllers | `src/View/GameView.php` (installer, admin message page, stored HTML such as spy reports) |
| Escaping policy | `src/Twig/GameEscaping.php`, registered by `src/Twig/EscaperConfigurator.php` |
| Template helpers and `LNG` | `src/Twig/TemplateExtension.php`, `src/Twig/LanguageView.php` |

## Writing a page

A page action collects its variables and returns the rendered page:

```php
public function show(): Response
{
    $this->assign(['planets' => $planets]);
    $this->addScript('empire.js');                       // public/scripts/game/empire.js (ES module)

    return $this->render('game/page.empire.default.html.twig');
}
```

- `render()` adds the area's layout data (navigation, clock, theme), the page scripts and
  `layout_template`, the layout the page extends (`<area>/layout.<window>.html.twig`). Page templates
  start with `{% extends layout_template %}`. Admin templates include `admin/overall_header.html.twig`
  and `admin/overall_footer.html.twig` instead.
- The window is `full` by default. `setWindow('popup')` gives the popup layout; XHR requests get `ajax`.
- Layout variables win over page variables of the same name.
- Code that cannot return the Response (a helper deep in the call chain, an error branch) ends the
  request with `$this->display($template)`, `$this->printMessage($text)`, `$this->redirectTo($url)`
  or `$this->sendJSON($data)`. They throw `App\Game\Core\EndOfRequest`, which carries the response.
- Admin: `show()` returns the page, and `$this->message($text, $redirectUrl, $seconds)` shows the
  notice page.

## Escaping

Autoescaping is on for every game template, with the `game_html` strategy.

The game HTML-encodes request input when it reads it (`HTTP::_GP()`), so names and texts in the
database are usually encoded already. `game_html` is `htmlspecialchars()` without double encoding:
raw text gets encoded and encoded text passes through unchanged. Every printed value is therefore
safe whichever way it was stored, and nothing shows up double-encoded.

What is printed unescaped:

- **Translations.** `{{ LNG.key }}` and `{{ LNG.tech[id] }}` are trusted and may contain markup.
  They stay trusted with `LNG.key|format(a, b)` and `sprintf(LNG.key, a, b)`, which escape the
  arguments. Concatenation (`LNG.key ~ x`) and other filters drop the trust mark.
- **Helper output.** `icon()`, `html_options()` and `{% set x %}…{% endset %}` capture blocks.
- **Code-built HTML.** Pass it as `new \Twig\Markup($html, 'UTF-8')`, and only when every dynamic
  part inside it is escaped or numeric.
- **Stored HTML.** Values that always hold HTML, such as message texts, rendered reports and
  sanitized CMS content (`App\Content\HtmlContent`), are printed with `|raw`.

Other contexts:

- JSON inside `<script>`: `{{ data|json_encode|raw }}`. JSON inside an HTML attribute stays escaped.
- Text inside an inline JavaScript string: `'{{ value|e('js') }}'`.
- New helpers that return markup declare `is_safe => ['all']`. A plain `['html']` does not cover
  `game_html`.

Templates outside the game areas (Symfony bundles, the profiler) use Twig's default escaping.

## Tables and forms (in-game)

`public/styles/resource/css/app/tables-forms.css` owns the layout of tables and forms in the game content area and in
dialogs (cascade layer `components`, above the themes); themes only colour them. Page templates say what a cell holds:

| Class | Use |
| --- | --- |
| (none) | text, left aligned; tables span the content width |
| `.num` | numbers and amounts, on the `<th>` and the `<td>`: right aligned, tabular figures |
| `.ctr` | icons, status, short codes: centred |
| `.act` | row buttons/links: centred, as narrow as their content |
| `.top` | cells holding blocks of different height (nested tables): top aligned |
| `table.form-table` | label / control rows: labels 40 %, inputs and selects one shared width |
| `.form-actions` | the submit row of a form |
| `input.num`, `type="number"` | amount fields (right aligned, shared width: 7.5rem, 5rem on phones) |
| `input.coord` | galaxy / system / position fields |
| `.control-group` | controls side by side in one cell (amount + "max", coordinates + type) |
| `.control-wide` | a text input or select that uses the whole cell |
| `<div class="table-scroll">` | around a wide table: it scrolls sideways on phones instead of widening the page |

`table.auto` and tooltip tables (`hoverinfo`, `tt-*`) keep their natural size. Page-specific layout goes into the
page's section of `css/app/content.css`; it cannot override the layout rules above (lower layer), so extend the shared
vocabulary instead of fighting it.

Cost: the file is 6 KB (1.9 KB gzipped) without `!important`; measured style/layout time of heavy pages (settings,
empire, galaxy, messages, fleet table, statistics) with and without it stays within measurement noise (±3 ms).

## Checks

- `bin/console lint:twig templates`
- `bin/console lint:container`

## Page behaviour without inline code (Content-Security-Policy)

The game sends a strict CSP (`script-src 'self' 'nonce-…'`), so templates contain no `on*=` attributes, `javascript:`
links or executable inline `<script>`. Instead:

- **Behaviour**: markup names an action, a page module registers it (`public/scripts/app/actions.js`).
  `<a href="#" data-action="max-ship" data-ship="ship202">`, `<select data-on-change="submit-form">`,
  `<form data-on-submit="check-target">` (also `data-on-input|keyup|focus|blur|error`). A module calls
  `registerActions({'max-ship': (element, event) => ...})`; a click action returns `false` to cancel the default
  (anchors with `href="#"` are always cancelled), a submit action returns `false` to stop the submit.
  Built-ins: `dialog-info|pm|buddy|playercard|ach|forward|show`, `popup`, `submit-form`, `toggle-rows`, `char-count`,
  `fill-input`, `filter-rows`, `open-url`, `history-back`, `reload`, `remove`. `data-confirm="question"` on a link, button or
  submit input asks first.
- **Load the module** with `<script type="module" src="./scripts/game/x.js?v={{ asset_version }}"></script>` in
  `{% block script %}` (or `addScript()`). Modules run once per page load; content in a modal fragment is new each
  time, so re-initialise on the `modal:load` event.
- **Data**: `{{ data_island('my-data', {key: value}) }}` prints `<script type="application/json" id="my-data">`
  (not executed, `<>&'"` escaped); read it with `pageData('my-data')` from `app/core.js`. Use unique ids (a dialog
  can sit on top of a page that has its own island). Twig `merge` renumbers integer keys: pass lists of rows, not maps.
- **Unavoidable inline scripts** (none today besides the importmap) carry `nonce="{{ csp_nonce() }}"`.
- **Styles**: inline `style=""` attributes and `<style>` blocks stay allowed (`style-src 'unsafe-inline'`).
- **Third parties**: a page that loads reCAPTCHA calls `{{ csp_allow('recaptcha') }}`.
- **Config**: `CSP_MODE=enforce|report|off`; reports at `/csp-report` -> `var/log/csp.log`.
