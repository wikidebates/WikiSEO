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
| Schema.org | `@type=WebPage` | `schema_type=AboutPage`, `ContactPage`, `Article`, etc. |
| Twitter / X | `summary_large_image` | `twitter_card=summary` |
| Date de publication | Absente | `published_time=` si la date est connue |
| Date de modification | Absente | `modified_time=` si la date est connue |
| Auteur | Absent | `schema_author=` pour les articles |
| Organisation editrice | Nom du wiki, si configure | `publisher` sous forme `Organization` |

Les types complexes comme `QAPage`, `FAQPage` ou `Question` ne sont pas proposes
par ce generateur minimal : ils exigent des donnees structurees completes,
produites par l'extension Wikidebates pour ses debats et arguments.

Les anciens parametres `type=article` et `type=website` restent acceptes.
Il est preferable de specifier separement `og_type` et `schema_type` dans
les nouvelles pages pour eviter toute ambiguite.

Les balises `article:*` ne sont generees que pour `og:type=article`.
Le logo n'est jamais supprime automatiquement : c'est le comportement que
Wikidebates a explicitement choisi de conserver.

## Desactivation de WikiSEO par page (PHP)

WikiSEO expose deux hooks configurables qui laissent les autres extensions
choisir son comportement **sans heuristique sur le titre ou l'espace de noms** :

- `WikiSEOPageMetadataMode( OutputPage $out, string &$mode )` : intercepte
  le rendu de la page avant les generateurs WikiSEO et avant le canonical.
- `WikiSEOPageRevisionMode( Title $title, ParserOutput $output, string &$mode )` :
  permet de desactiver les mises a jour differees de description et d'image
  sur les revisions gerees par une autre extension.

Pour les deux hooks, `$mode` commence a `full` et accepte :

| Mode | Balises produites |
| --- | --- |
| `full` | Fonctionnement historique de WikiSEO, y compris ses metadonnees automatiques |
| `global-only` | Uniquement les balises techniques non concurrentes : verification de site, liens hreflang de configuration et protections robots |
| `off` | Aucun ajout WikiSEO au rendu, aucune actualisation differee de l'image ou de la description |

Wikidebates utilise `global-only` quand son marqueur natif
`wikidebates-native-seo` indique `wd_kind=debate` ou `wd_kind=argument`.
Les debat et argument gardent donc le SEO issu de Wikidebates (y compris le
JSON-LD) et les verifications globales, sans generateurs de page concurrents.
Un rendu sans marqueur conserve le mode `full`.

Exemple supplementaire pour un administrateur voulant desactiver **toute**
contribution de WikiSEO sur une page particuliere, dans `LocalSettings.php` :

```php
$wgHooks['WikiSEOPageMetadataMode'][] = static function(
	\OutputPage $out, string &$mode
): void {
	$title = $out->getTitle();
	if ( $title !== null && $title->getPrefixedText() === 'Aide:Essai' ) {
		$mode = 'off';
	}
};
```

Ce callback n'agit que sur l'affichage de la page. Si la page contient du
`{{#seo:…}}` ancien, la fonction de parser continue de fonctionner et
d'enregistrer ses valeurs ; elles ne sont pas emises dans le HTML tant que
le rendu est en mode `off`. Pour des exclusions permanentes, utiliser aussi
le hook `WikiSEOPageRevisionMode` et la meme source de verite.

**Attention :** le mode `off` supprime aussi les balises de verification de
domaine produites par WikiSEO. Choisir `global-only` si elles doivent rester.
Aucune configuration n'est changee automatiquement par la mise a jour.

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
