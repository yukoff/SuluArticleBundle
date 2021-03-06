# Installation

## Install the bundle
 
```bash
composer require sulu/article-bundle
```

### ElasticSearch

The SuluArticleBundle requires a running elasticsearch `^2.2` or `^5.0`.

There is an different installation and configuration depending on which version of ElasticSearch you are using.

If you use version `^2.2` read: [Installation for ElasticSearch 2.2](installation_es2.md)
else read: [Installation for ElasticSearch 5.0](installation_es5.md) 

### Add bundles to AbstractKernel

```php
/* app/AbstractKernel.php */

new Sulu\Bundle\ArticleBundle\SuluArticleBundle(),
new ONGR\ElasticsearchBundle\ONGRElasticsearchBundle(),
```

### Configure SuluArticleBundle and sulu core

```yml
# app/config/config.yml

sulu_route:
    mappings:
        Sulu\Bundle\ArticleBundle\Document\ArticleDocument:
            generator: schema
            options:
                route_schema: /articles/{object.getTitle()}

sulu_core:
    content:
        structure:
            default_type:
                article: "article_default"
                article_page: "article_default"
            paths:
                article:
                    path: "%kernel.root_dir%/Resources/templates/articles"
                    type: "article"
                article_page:
                    path: "%kernel.root_dir%/Resources/templates/articles"
                    type: "article_page"
```

### Configure the routing

```yml
# app/config/admin/routing.yml

sulu_arictle_api:
    resource: "@SuluArticleBundle/Resources/config/routing_api.xml"
    type: rest
    prefix: /admin/api

sulu_article:
    resource: "@SuluArticleBundle/Resources/config/routing.xml"
    prefix: /admin/articles
```

## Create Template

Add xml template for structure in configured folder:

```
%kernel.root_dir%/Resources/templates/articles/article_default.xml
```

Example is located in Bundle
[article_default.xml](https://github.com/sulu/SuluArticleBundle/blob/master/Resources/doc/article_default.xml).

Add template for article type in configured folder: ``

```
%kernel.root_dir%/Resources/views/articles/article_default.html.twig
```

Example is located in Bundle
[article_default.xml](https://github.com/sulu/SuluArticleBundle/blob/master/Resources/doc/article_default.html.twig).

## Initialize bundle

Create assets:

```bash
php bin/console assets:install
```

Create translations:

```bash
php bin/console sulu:translate:export
```

Create required phpcr nodes:

```bash
php bin/console sulu:document:init
```

Create elasticsearch index:

```bash
php bin/console ongr:es:index:create
```

## Possible bundle configurations:

```yml
# app/config/config.yml

sulu_article:
    documents:
        article:
            view: Sulu\Bundle\ArticleBundle\Document\ArticleViewDocument
    types:

        # Prototype
        name:
            translation_key:      ~

    # Display tab 'all' in list view
    display_tab_all:      true
```


