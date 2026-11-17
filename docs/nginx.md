The document root is `public/`. Only that directory is served; everything else in the
repository (config, includes, language, cache, ...) is not reachable from the web.

All requests that don't match a file in `public/` go to the front controller
`public/index.php` (Symfony). That includes the game's own URLs like `/game/overview`,
`/admin/bans` or `/account/board`, which are routes in Symfony, not files.

Example nginx server block (the `map` belongs in the `http` context, e.g. a file in `conf.d/`):

```
# serve foo.png.webp instead of foo.png to browsers that accept WebP (copies made by bin/console app:images:webp;
# the theme images already ship as WebP, so this only matters for the remaining JPG/PNG/GIF files)
map $http_accept $webp_suffix {
    default        "";
    "~*image/webp" ".webp";
}

# CSS/JS URLs carry ?v=<git hash or mtime>: cache them for good; unversioned ones revalidate
map $arg_v $asset_cache_control {
    default "public, max-age=31536000, immutable";
    ""      "no-cache";
}

# compression (add `brotli on; brotli_types ...;` with the ngx_brotli module for br)
gzip on;
gzip_comp_level 5;
gzip_vary on;
gzip_types text/css application/javascript text/javascript application/json image/svg+xml;

server {
    server_name br0game.example;
    root /var/www/br0game/public;

    # Universe prefixes for static files: /uni2/styles/... -> /styles/...
    location ~ ^/uni[0-9]+/(styles|scripts)/ {
        rewrite ^/uni[0-9]+/(.*)$ /$1 last;
    }

    # versioned CSS/JS: immutable (see public/.htaccess)
    location ~* \.(css|js|mjs)$ {
        add_header Cache-Control $asset_cache_control;
        try_files $uri =404;
    }

    # WebP copies of static images (same as the Apache rule in public/.htaccess)
    location ~* \.(png|jpe?g|gif)$ {
        add_header Vary Accept;
        add_header Cache-Control "public, max-age=31536000, stale-while-revalidate=604800";
        try_files $uri$webp_suffix $uri =404;
    }

    location ~* \.(svg|woff2?|ico)$ {
        add_header Cache-Control "public, max-age=31536000, stale-while-revalidate=604800";
        try_files $uri =404;
    }

    # Theme metadata files are read by PHP, not served
    location ~ \.cfg$ {
        deny all;
    }

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
    }

    # a request for any .php file goes to the front controller (the game's URLs are Symfony routes, not files)
    location ~ \.php$ {
        rewrite ^ /index.php last;
    }
}
```


