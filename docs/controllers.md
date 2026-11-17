# Controllers, routes, input and responses

All four areas (public pages, `/game`, `/account`, `/admin`) are Symfony controllers in
`src/Controller/{Login,Game,Account,Admin}/`, one `#[GameRoute]` per action.

## Routes

```php
#[GameRoute('account', 'account/board/{id}', 'board', 'post', requirements: ['id' => '\d+'])]
public function post(): Response
```

`GameRoute(area, path, page, mode = 'show', methods = ['GET'], requirements = [], defaults = [])`
(`src/Routing/GameRoute.php`)

- `area`: `index` (public pages, route prefix `login`), `game`, `account`, `admin`. It selects the boot mode.
- `path`: after the optional `/uniN/` prefix, no leading slash, `''` = start page. Never name a placeholder `uni`.
- Route name is `<area>_<page>_<mode>` in snake_case (`login_lost_password_send`, `game_fleet_step1_check_target`).
  The route defaults `_area`, `_page`, `_mode` (lower case) are what `CsrfPolicy`, `GameRateLimits`, the layouts
  and `GameBootstrap` read. Never read `?page=` / `?mode=`.
- GET for reads, `['POST']` for every state change. Two URLs for one action: repeat the attribute.
- `#[RequireModule('MODULE_X')]` on the class is enforced for every route (`GuardsModule`, called by
  `GameControllerListener` after `initialize()`).
- Unknown path = Symfony 404. `config/packages/security.yaml` `access_control` protects `/game`, `/admin` and
  `/account` (public exceptions by `_route`).
- A trait shared by areas cannot carry the attribute: alias it (`use TeamPage { show as protected renderTeam; }`)
  and declare `show()` in the controller.

## Input

`$this->input()` (`RequestInput`) is the typed front of `HTTP::_GP()`: `int`, `float`, `bigNumber`, `string($name,
$default, $multibyte)`, `rawString` (passwords), `intList`, `stringList`, `has`, `bool`. Values are cast, trimmed and
HTML-encoded like before: never encode a returned string again (`docs/templates.md`). Path placeholders are merged
into the input (path wins), so `->int('id')` reads path, query and form alike. Raw POST bodies (rich text) via
`$this->http()->post()`. Deep game code (`src/Game/`, installer) still calls `HTTP::_GP()` directly.

## Responses

An action returns a Response, it does not throw `EndOfRequest`:

| need | use |
|---|---|
| page | `return $this->render('t', [...]);` (context wins over `assign()`ed vars) |
| message page | `return $this->messageResponse($msg, $buttons);` |
| redirect to a route | `return $this->redirectToRoute('account_board_post', ['id' => $id, '_fragment' => 'c5']);` |
| redirect to a path (relative to the universe dir) | `return $this->redirectResponse('account');` |
| JSON | `return $this->jsonResponse($data);` (Content-Type text/html as before) |
| helper that may refuse | helper returns `?Response`; caller `if ($failure = $this->helper()) { return $failure; }` |

`EndOfRequest` is still thrown by `initialize()` (guest redirect), `printPageError()`/`display()`/`printMessage()`/
`redirectTo()`/`sendJSON()` and deep helpers that cannot return. `beforeResponse()` is a hook (Login saves state there).

## URL generation

- Twig: `{{ path('account_board_show') }}`, `{{ path('login_news_article', {id: item.id}) }}`; extra keys become the
  query. The URL stays inside the current `uniN/` directory (`GameControllerListener` sets the router context).
- PHP: `$this->routeUrl('name', [...])` (absolute), `$this->router->generate('name')`, `redirectToRoute()`.
  Mail links: `CanonicalUrl::forPage($runtime, 'verify?i=1&k=x')`.
- JS never builds paths from page/mode: render the URL into the markup (`action="{{ path(...) }}"`,
  `data-*-url="{{ path(...) }}"`, or `data_island(...)`). `universe_path('route')` gives the route relative to a
  universe directory for scripts that compose the URL themselves.
- All area layouts set `<base href>` to the site root/universe directory, so relative asset URLs work at any depth.
- Language: `?lang=xx` is honoured on every area (cookie + page language); the start page select reloads the current URL with it.

## CSRF and rate limits

- `CsrfPolicy`: every POST (PUT, PATCH, DELETE) to a game controller needs the token (header `X-CSRF-Token`, field or
  query `_csrf`; `public/scripts/app/csrf.js`). A state-changing GET (logout link, phalanx) is listed by route name in
  `CsrfPolicy::GET_ROUTES`. Cross-site top-level navigation into game/account/admin pages is bounced unless the page
  is in `CROSS_SITE_PAGES`.
- `GameRateLimits` (`RateLimitListener`): POST routes and `GET_ROUTES` use the `game_actions` bucket, the route names in
  `EXPENSIVE_ROUTES` (galaxy, player card, search, statistics, battle hall, reports, records, fleet check-target /
  check-resources, message user search) the `game_reads` bucket, `ANONYMOUS_ROUTES` (battle report, board) are limited
  per IP. New expensive read: add its route name.
