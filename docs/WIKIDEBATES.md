# WikiSEO pour Wikidebates — MediaWiki 1.43 / PHP 8.4

Ce document decrit la version developpee dans ce depot et les valeurs de
metadonnees adaptees aux pages generales et institutionnelles. La generation
specialisee des pages de debat/argument releve de l'extension **Wikidebates**.

## Principes

- Les pages de debat et d'argument avec marqueur natif utilisent le mode
  `global-only` de WikiSEO ; les pages ordinaires gardent le mode `full`.
  Les interactions HTML des deux systemes doivent etre testees avant deploiement.
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
Les modifications de modernisation et d'optimisation sont integrees dans
`main`. La ferme MediaWiki (revision
`f0e2bf8a7fd3d903c7ac237fe76aa455de74e6bd`) reference le code de production
WikiSEO `ca976844f6a8c038080684cf566aaaa4093cef9c`, sur MediaWiki 1.43.12.
Les changements limites aux tests et a la documentation ne necessitent pas
de mise a jour du gitlink de production.

## Optimisations sans modification du rendu

- Lecture groupee des proprietes du ParserOutput pour les appels `#seo` et
  `<seo>` successifs (ordre de la liste autorisee et valeurs conserves).
- Validation des noms par une table de correspondance en memoire du processus,
  derivee de la liste autorisee existante. Les ajouts/retraits des noms de
  generateurs et des codes de langues invalident les listes calculees.
- En presence d'une image X explicite, ne pas preparer l'image generale,
  l'image de secours ni l'URL sociale qui ne seront pas utilisees par X.
  Les generateurs Open Graph et Schema.org ne changent pas.

Les tests `tests/standalone/parser_work.php` et `generators.php` comparent
le comportement au commit precedent `22cc1b7f65d1bd058b7178393dbb17d3c015063f`.
Les temps de microbenchmark PHP sont informatifs, sans seuil de CI. Aucun
nouveau cache disque ou partage entre requetes n'est introduit.

### Resolution mutualisee des images

Open Graph, X et Schema.org reutilisent les resultats de recherche de fichier,
les dimensions et l'horodatage deja lus, ainsi que la decision PageImages si
une image par defaut est configuree. Le partage ne dure que le temps d'un
appel `WikiSEO::addMetadataToPage()`, entre generateurs integres consecutifs.
Un generateur facultatif/personnalise termine ce partage ; les donnees ne
sont jamais conservees entre pages ou entre deux appels. Les erreurs
inattendues des services ne sont pas mises en cache. Chaque generateur
conserve sa propre selection d'image (y compris X explicite et URL sociale).
Les tests comparent les appels aux services et le hash du HTML/JSON-LD avec
le code precedent. Les compteurs ne sont pas des mesures de requetes SQL.

### Metadonnees globales et proprietes textuelles

Les services de langue ne sont sollicites que pour les sous-pages susceptibles
d'etre des traductions ; la fabrique de langues ne sert que si la traduction
est reconnue. Les regles robots, les verifications de domaine et les liens
hreflang sont conserves. Les balises existantes ne sont parcourues que si
une metadonnee de page doit etre ajoutee. Les valeurs textuelles ordinaires
ne passent plus par unserialize(); toutes les formes PHP potentiellement
serialisees conservent le decodeur avec allowed_classes=false.
Le test global_work.php compare les sorties, les compteurs et les temps
informatifs avec le commit precedent 0e9d121e6ba25a42e771d818a3a3841fa468a3fd. Aucun cache persistant.

## Benchmark cumule de WikiSEO

Le workflow `seo-combined-benchmark.yml` compare trois versions sur la meme
machine CI, avec MediaWiki 1.43.12, PHP 8.4 et Wikidebates
`21d0c0d27ac72b9392d859d695c56230a868d772` :

- `official` : revision Wikimedia REL1_43
  `0496485749063be68fbe3a3aa1bd444979ec262a` ; le dernier gitlink officiel de
  la ferme, `3b135415f9fe0eff4d23659b4143bd50009238ab`, a le meme code PHP.
- `baseline` : premiere modernisation
  `3f52f9d7d196bcaed541201f4412f4bf1e6b71e1`.
- `optimized` : code du candidat (revision finale de production
  `ca976844f6a8c038080684cf566aaaa4093cef9c` lors de l'introduction du test).

La base SQLite jetable est reconstruite pour chaque processus ; les pages
et fichiers du catalogue sont synthetiques.
Deux passages d'ordre inverse reduisent les biais d'ordre. Les mesures
separent le rendu SEO et la construction du head de l'analyse du wikitexte.
Les compteurs SQL sont issus des traces Database::executeQuery, dans des
passages distincts des chronometrages. La memoire est le pic incremental
PHP sur un lot identique, non la RAM du serveur. Les empreintes des metadonnees
(et des proprietes parser) doivent correspondre exactement entre `baseline`
et `optimized`, et rester stables dans chaque version. La sortie officielle
peut differer en raison des corrections et fonctionnalites de modernisation.
Ce test ne mesure
ni les caches HTML ni la latence HTTP ou MariaDB de production. Pas de seuil
chronometrique bloquant : les temps sont informatifs. Le workflow est lance
sur les PR modifiant son banc d'essai, ou manuellement.

Le [benchmark historique sur MediaWiki 1.43.11](https://github.com/wikidebates/WikiSEO/actions/runs/45388300714)
a mesure une reduction du CPU de 36,5 a 36,8 % pour les cas natifs, de
20,2 a 28,4 % pour les pages ordinaires et de 5,9 % pour le cas parser
(de la version officielle au candidat). Les trois cas natifs ont conserve
leur empreinte SEO finale. Ces chiffres concernent les operations mesurees,
non le rendu HTML complet ou les pages servies depuis un cache fichier.

## Nom du site sur la page d'accueil (optionnel)

Google distingue le nom du site du titre de la page. Pour un wiki dont
l'accueil est accessible à la racine du domaine ou sous-domaine, activer
le générateur SchemaOrg et renseigner les valeurs suivantes par wiki :

```php
$wgWikiSeoWebsiteUrl = 'https://fr.wikidebates.org/';
$wgWikiSeoWebsiteName = 'Wikidébats';
```

Sur le wiki anglais, utiliser sa propre racine et le nom Wikidebates. Si le
nom n'est pas renseigné, Sitename est utilisé. L'URL est explicitement
configurée : le code ne suppose pas que tout wiki est installé à la racine.
Le générateur ajoute un nœud WebSite dans le même JSON-LD que la page
principale indexable ; les pages ordinaires, les modes global-only/off et
les installations sans cette configuration conservent leur sortie. Aucun
appel distant, aucune lecture d'historique, aucun nouveau cache. Ne pas
activer avant d'avoir vérifié l'URL canonique et l'accès public à la racine.
La description éditoriale de l'accueil reste gérée par le modèle SEO projet.
