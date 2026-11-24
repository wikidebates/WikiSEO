# Interwiki hreflang (opt-in)

WikiSEO can publish the effective MediaWiki interlanguage links in the final
HTML head. It does not require Interlanguage or Wikidebates, and works with
any provider that populates OutputPage language links.

For a six-edition farm with canonical article paths /wiki/$1, use the same
configuration on all editions, with their own existing $wgLanguageCode:

```php
$wgEnableCanonicalServerLink = true;
$wgWikiSeoDefaultLanguage = null;
$wgWikiSeoInterlanguageArticleUrls = [
	'fr' => 'https://fr.wikidebates.org/wiki/$1',
	'en' => 'https://en.wikidebates.org/wiki/$1',
	'de' => 'https://de.wikidebates.org/wiki/$1',
	'es' => 'https://es.wikidebates.org/wiki/$1',
	'it' => 'https://it.wikidebates.org/wiki/$1',
	'pt' => 'https://pt.wikidebates.org/wiki/$1',
];
```

Verify every configured route against the actual canonical URL of that edition
before enabling it. These are infrastructure routes, not page mappings.
Prefer building this array from the farm's existing canonical site settings.
Only enable editions that are intended to be indexed. If an edition serves
its main page canonically at the domain root, the corresponding entry may be
an array with article, mainPageTitle and mainPageUrl keys. Example:

```php
$wgWikiSeoInterlanguageArticleUrls['fr'] = [
	'article' => 'https://fr.wikidebates.org/wiki/$1',
	'mainPageTitle' => 'Wikidébats:Accueil',
	'mainPageUrl' => 'https://fr.wikidebates.org/',
];
```

Do this only if the root is actually the canonical URL. Central mappings can
use canonical MediaWiki namespace names (such as Project) even when the
target wiki has a localized namespace URL. Supply explicitly verified
namespace aliases on each route; the map translates a namespace prefix,
never a page title or a translation relationship. For example:

```php
$wgWikiSeoInterlanguageArticleUrls['fr'] = [
	'article' => 'https://fr.wikidebates.org/wiki/$1',
	'namespaceAliases' => [
		'Project' => 'Wikidébats',
		'Wikidébats' => 'Wikidébats',
		'Help' => 'Aide',
		'Aide' => 'Aide',
	],
];
```

Title whitespace (including MediaWiki's Unicode space characters), repeated
underscores, leading/trailing whitespace and optional initial colon are
normalized before URL generation. Only configured namespace aliases change
the interpretation of the first colon. Other colons retain their meaning.
Aliases are matched case-insensitively after whitespace normalization.
Conflicting alias definitions or nonempty section fragments are rejected.

First-letter capitalization is **opt-in per localized namespace**, because
remote case rules may differ from the source wiki's rules. For instance,
after verifying the receiving wiki's namespace configuration:

```php
$wgWikiSeoInterlanguageArticleUrls['fr']['namespaceAliases']['Template'] = 'Modèle';
$wgWikiSeoInterlanguageArticleUrls['fr']['namespaceAliases']['Modèle'] = 'Modèle';
$wgWikiSeoInterlanguageArticleUrls['fr']['namespaceCase'] = [
	'' => 'first-letter', // main namespace
	'Modèle' => 'first-letter',
	'Module' => 'case-sensitive', // example only: verify actual configuration
];
```

Keys use the localized DB-key spelling (underscores instead of spaces).
Unspecified namespaces preserve case. An optional route-level
`ucfirstOverrides` map reflects the receiver's
`$wgOverrideUcfirstCharacters` configuration. No remote page or namespace
lookups are made in the final-head hook; use receiver-verified configuration,
not guessed capitalization. The route is not a redirect resolver. Verify the map against the target site's namespace settings,
not the source language's settings. The automatic mode still does not
resolve remote redirects, unknown aliases, or assert that a target is
indexable. Verify these and reciprocal links separately; checking them on
every page view would require remote network or database reads.

The effective OutputPage language links already include contributions from
Interlanguage. The feature does not run the LanguageLinks hook again, call
Skin::getLanguages(), resolve interwiki prefixes, query SQL, call HTTP, or
persist any new state. The URL encoding is the same as MediaWiki's
Interwiki::getURL(). UI filtering and hidden language selectors do not matter.

An empty map disables the feature and preserves the legacy behavior. A
nonempty map deliberately owns hreflang in the final head, including any
existing single-link hreflang annotations; do not combine this mode with
another hreflang provider or the legacy subpage/editorial mode. Other
alternate links (feeds, mobile representations) are preserved. No x-default
is generated. Only an indexable current article view with an exact matching
final canonical URL and at least one eligible translation gets a cluster.
Untranslated pages, restricted pages, revision/diff/action views and
canonical aliases are excluded. The local language uses the final canonical.
