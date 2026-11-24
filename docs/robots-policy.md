# Indexation et aperçu des images

Les directives robots de #seo peuvent renforcer les restrictions de MediaWiki
et de WikiSEO (noindex, nofollow, none), mais ne peuvent pas les annuler.
Les exclusions de titres et de paramètres d'URL restent applicables même
lorsqu'une page contient robots=index,follow.

Pour autoriser les aperçus de grande taille sur les pages ordinaires :

```php
$wgWikiSeoLargeImagePreview = true;
```

L'option est désactivée par défaut. Elle remplace uniquement le tag robots
exactement égal au défaut MediaWiki 1.43 max-image-preview:standard, en mode
full et sur une vue d'article. Elle laisse intacts les autres directives,
noindex/nofollow, les options supplémentaires et les pages natives en mode
global-only/off. Le défaut standard et un standard explicitement demandé
mais identique au défaut ne sont pas distinguables par l'API OutputPage 1.43 :
ne pas activer l'option si cette distinction est indispensable. Les
restrictions robots indépendantes (par exemple un meta googlebot) restent
applicables. Cette option n'ajoute ni requête SQL, ni accès distant, ni cache.

Documentation Google :
https://developers.google.com/search/docs/crawling-indexing/robots-meta-tag
