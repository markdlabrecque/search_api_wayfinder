# Search API Wayfinder

Drupal Search API connector module for [Wayfinder](https://github.com/markdlabrecque/wayfinder).

## Installing via Composer (from GitHub, not drupal.org)

This module is not published on drupal.org or Packagist. To require it in a
Drupal project's `composer.json`, add a VCS repository pointing at this
GitHub project, then require it by package name.

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/markdlabrecque/search_api_wayfinder.git"
        }
    ],
    "require": {
        "markdlabrecque/search_api_wayfinder": "dev-main"
    }
}
```

Or from the command line:

```sh
composer config repositories.search_api_wayfinder vcs https://github.com/markdlabrecque/search_api_wayfinder.git
composer require markdlabrecque/search_api_wayfinder:dev-main
```

Composer places the module under `modules/contrib/search_api_wayfinder` via
`drupal/core-composer-scaffold` / `composer/installers`, same as any other
`drupal-module` package.

Pin to a tagged release instead of `dev-main` once this project starts
tagging versions (e.g. `"markdlabrecque/search_api_wayfinder": "^1.0"`).
