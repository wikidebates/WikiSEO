# WikiSEO pour Wikidebates — MediaWiki 1.43 / PHP 8.4

Ce document decrit la version developpee dans ce depot et les valeurs de
metadonnees adaptees aux pages generales et institutionnelles. La generation
specialisee des pages de debat/argument releve de l'extension **Wikidebates**.

## Principes

- **Aucune desactivation** de WikiSEO sur les pages generees par l'extension
  Wikidebates. Les interactions HTML des deux systemes doivent etre testees
  avant la mise en production.
- Le hook MediaWiki `BeforePageDisplay` de WikiSEO est toujours actif :
  **meme sans `#seo` ou `<seo>`**, un socle de metadonnees est produit.
- On ne doit jamais annoncer automatiquement que toutes les pages sont des
  articles, attribuer un auteur non documente ou assimiler leur derniere
  modification a leur date de publication.
- Image explicite > image WikiSEO configuree > logo du wiki. Conserver le
  repli existant vers le logo quand aucune image n'a ete fournie.
- Les pages SEO specifiques sont marquees **manuellement** par leurs auteurs.

## Types par defaut

| Type de metadonnee | Defaut | Adaptation possible |
| --- | --- | --- |
| Open Graph | `og:type=website` | `og_type=article` pour les vrais articles |
| Schema.org | `@type=WebPage` | `schema_type=AboutPage`, `ContactPage`, `Article`, `QAPage`, etc. |
| Twitter / X | `summary_large_image` | `twitter_card=summary` |
| Date de publication | Absente | `published_time=` si la date est connue |
| Date de modification | Absente | `modified_time=` si la date est connue |
| Auteur | Absent | `schema_author=` pour les articles |
| Organisation editrice | Nom du wiki, si configure | `publisher` sous forme `Organization` |

Les anciens parametres `type=article` et `type=website` restent acceptes.
Il est preferable de specifier separement `og_type` et `schema_type` dans
les nouvelles pages pour eviter toute ambiguite.

Les balises `article:*` ne sont generees que pour `og:type=article`.
Le logo n'est jamais supprime automatiquement : c'est le comportement que
Wikidebates a explicitement choisi de conserver.

## Modele MediaWiki facultatif

Le fichier `docs/templates/SEO_projet.wikitext` est le contenu propose
de `Modele:SEO projet` sur le wiki francais. Copiez ce contenu **dans le wiki**
seulement apres validation de la version de WikiSEO qui comprend les nouveaux
parametres. Son equivalent anglais se trouve dans
`docs/templates/SEO_project.wikitext` (`Template:SEO project`).

Exemple d'utilisation :

```wikitext
{{SEO projet
	|description=Decouvrez le projet Wikidebates, son fonctionnement et ses objectifs.
}}
```

Les trois parametres usuels sont `description`, `titre` et `image`.
`schema` est un parametre avance facultatif, par exemple `schema=AboutPage`.
Si `titre` est absent, le titre HTML MediaWiki n'est pas remplace. Si
`image` est absent, le repli sur l'image par defaut ou le logo reste possible.

## Exemple avance sans modele

```wikitext
{{#seo:
	|description=Notre projet et sa demarche collaborative.
	|og_type=website
	|schema_type=AboutPage
	|twitter_card=summary_large_image
}}
```

## Precautions de mise en production

1. Tester le code avec une veritable installation MediaWiki 1.43 et PHP 8.4,
   en francais et en anglais ; les tests de simulation PHP ne suffisent pas.
2. Tester les pages sans modele SEO, avec `#seo`, avec `<seo>`, avec le
   modele, et les pages de debat/argument gerees par Wikidebates.
3. Tester suppression et modification de valeurs `page_props` apres edition,
   purge, regeneration de ParserCache, inclusion et modification de modele.
4. Verifier les caches fichier Vector / Minerva et leur invalidation.
5. Valider HTML (og:, twitter:, canonical, hreflang, robots) et JSON-LD ;
   verifier HTTPS et l'acces des robots de partage aux images via Cloudflare.
6. Ne mettre a jour le sous-module de la ferme MediaWiki que dans une PR
   distincte, apres approbation et validation.

Le depot conserve l'historique original Wikimedia depuis la branche
`REL1_43`, commit `0496485749063be68fbe3a3aa1bd444979ec262a`.
Les modifications de ce chantier se trouvent dans une branche
`feature/seo-modernization-mw143-20261124`, sans modification du serveur.
