
# Configuration

## Personnaliser la vue

Ouvrez les paramètres avec le bouton en forme de roue dentée dans la barre de lecture. La page d’accueil utilise une seule interface de lecture, dont l’apparence se règle ici.

### Thème et couleurs de jour et de nuit

La catégorie d’apparence propose 32 palettes. Chaque sélecteur affiche d’abord le choix actuel. Le bouton de changement de thème ouvre une liste de six choix au maximum par page, avec des filtres clair et sombre et une recherche par nom ou description de palette. La recherche, les filtres et la pagination ne modifient pas le thème sélectionné.

Vous pouvez choisir un thème fixe ou suivre le mode clair ou sombre de l’appareil avec deux thèmes distincts pour le jour et la nuit. Les nouveaux comptes utilisent Sunset ; les palettes par défaut du mode automatique sont Nord le jour et Sunset la nuit.

La sélection met à jour l’aperçu local. Vous pouvez agrandir cet aperçu pour comparer la liste des abonnements, la liste des articles et le contenu d’un article ; sur téléphone, ces trois zones se consultent séparément. Enregistrez les paramètres pour appliquer l’apparence à l’interface de lecture. Un retour à la lecture sans enregistrer conserve l’apparence déjà sauvegardée ; en cas d’échec de l’enregistrement, les modifications restent disponibles pour une nouvelle tentative.

### Disposition, densité et style de lecture

Les dispositions proposées sont la vue en colonnes, la liste et les cartes. Lorsque la place le permet, la vue en colonnes affiche côte à côte les abonnements, la liste des articles et l’article ouvert. La liste et les cartes proposent d’autres présentations pour parcourir les articles. Sur un écran étroit, l’interface s’adapte à la place disponible sans modifier la préférence enregistrée.

La densité de la liste peut être confortable ou compacte. Les styles de lecture proposent des surfaces discrètes, une présentation avec une police à empattements rappelant le papier, ou des bordures et des textes secondaires plus marqués. Ils conservent les couleurs de la palette sélectionnée.

### Typographie et largeur des articles

La catégorie de lecture permet de régler la taille du texte des articles, l’interligne et la largeur maximale du contenu. Elle propose aussi les largeurs souhaitées pour la barre des abonnements et la liste des articles en vue en colonnes. Si l’écran est trop étroit, l’interface ajuste les largeurs affichées sans remplacer les valeurs enregistrées.

Ces réglages numériques remplacent les anciens choix Fine, Moyenne, Large et Pas de limite. Le réglage des icônes d’en-tête et de pied d’article et les anciennes vues normale, globale et de lecture ne font plus partie de l’interface de lecture.

### Langue et fuseau horaire

Ouvrez l’outil de langue et de fuseau horaire depuis la catégorie d’apparence. Choisissez la langue de l’interface et le fuseau horaire utilisé pour les dates, puis validez les modifications dans ce formulaire. Le fuseau par défaut correspond à celui de l’instance.

Les traductions peuvent être incomplètes selon la langue. Pour participer, consultez les [instructions de contribution aux traductions](../contributing.md#contribute-to-internationalization-i18n).

## Reading

> **À FAIRE**

## Archivage

> **À FAIRE**

## Partage

Pour vous faciliter la vie, vous pouvez partager des articles directement
via FreshRSS.

At the moment, FreshRSS supports 18 sharing methods, ranging from
self-hosted services (Shaarli, etc.) to proprietary services (Facebook,
etc.).

By default, the sharing list is empty.  ![Sharing
configuration](../../en/img/users/configuration.sharing.png)

Pour ajouter un nouvel élément à la liste, veuillez suivre les étapes
simples ci-dessous :

1. Select the desired sharing method in the drop-down list.
2. Press the ```✚``` button to add it to the list.
3. Configure the method in the list. All names can be modified in the
	display. Some methods need the sharing URL to be able to work properly
	(ex: Shaarli).
4. Submit your changes.

To remove an item from the list, follow those simple steps:

1. Press the ```❌``` button next to the share method you want to remove.
2. Submit your changes.

## Raccourcis

To ease the use of the application, FreshRSS comes with a lot of predefined
keyboard shortcuts.  They allow actions to improve the user experience with
a keyboard.

Of course, if you’re not satisfied with the key mapping, you can change you
configuration to fit your needs.

There are 4 types of shortcuts:

1. Views: they allow switching views with ease.
2. Navigation: they allow navigation through articles, feeds, and
	categories.
3. Article actions: they allow interactions with an article, like sharing
	or opening it on the original web-site.
4. Other actions: they allow other interactions with the application, like
	opening the user queries menu or accessing the documentation.

It’s worth noting that the share article action has two levels. Once you
press the shortcut, a menu containing all the share options opens.  To
choose one share option, you need to select it by its number. When there is
only one option, it’s selected automatically though.

The same process applies to the user queries.

Be aware that there is no validation on the selected shortcuts.  This means
that if you assign a shortcut to more than one action, you’ll end up with
some unexpected behavior.

## User queries

You can configure your [user queries](./03_Main_view.md) in that
section. There is not much to say here as it is pretty straightforward.  You
can only change user query titles or drop them.

At the moment, there is no helper to build a user query from here.

## Users

> **À FAIRE**

### Authentication methods

#### HTTP Authentication (Apache)

1. User control is based on the `.htaccess` file.
2. It is best practice to place the `.htaccess` file in the `./i/`
	subdirectory so the API and other third party services can work.
3. If you want to limit all access to registered users only, place the file
	in the FreshRSS directory itself or in a parent directory. Note that
	WebSub and API will not work!
4. Example `.htaccess` file for a user "marie":

```apache
AuthUserFile /home/marie/repertoire/.htpasswd
AuthGroupFile /dev/null
AuthName "Chez Marie"
AuthType Basic
Require user marie
```

Plus d’informations dans [la documentation
d’Apache.](https://httpd.apache.org/docs/trunk/howto/auth.html#gettingitworking)

## Gestion des flux

### Informations

> **À FAIRE**

### Archivage des flux

> **À FAIRE**

### Identification

> **À FAIRE**

## Avancé

### Récupérer un flux tronqué à partir de FreshRSS

La question revient régulièrement, je vais essayer de clarifier ici comment
on peut récupérer un flux RSS tronqué avec FreshRSS. Sachez avant tout que
la manière de s’y prendre n’est absolument pas "user friendly", mais elle
fonctionne. :)

Sachez aussi que par cette manière vous générez beaucoup plus de trafic vers
les sites d’origines et qu’ils peuvent vous bloquer par conséquent. Les
performances de FreshRSS sont aussi moins bonnes car vous devez alors aller
chercher le contenu des articles un par un. C’est donc une fonctionnalité à
utiliser avec parcimonie !

Ce que j’entends par "Chemin CSS des articles sur le site d’origine"
correspond en fait au "chemin" constitué par les IDs et les classes (en
html, correspond aux attributs id et class) pour récupérer uniquement la
partie intéressante qui correspond à l’article. L’idéal est que ce chemin
commence par un id (qui est unique pour la page).

#### Exemple : Rue89

Pour trouver ce chemin, il faut se rendre à l’adresse d’un des articles tronqués.
Il faut alors chercher le "bloc" HTML correspondant au contenu de l’article
(dans le code source !)

On trouve ici que le bloc qui englobe uniquement le contenu de l’article est ```<div class="content clearfix">```. On ne va garder que la classe `.content` ici. Néanmoins, comme je le disais plus haut, il est préférable de commencer le chemin avec un id. Si on remonte au bloc parent, il s’agit du bloc ```<div id="article">``` et c’est parfait ! Le chemin sera donc ```#article .content```.

#### Liste de correspondances site → chemin css

* Rue89 : ```#article .content```
* PCINpact : ```#actu_content```
* Lesnumériques : ```article#body div.text.clearfix```
* Phoronix : ```#main .content```

### Récupérer un flux tronqué à l’aide d’outils externes

Des outils complémentaires peuvent être utilisés pour récupérer le contenu
complet d’un article, comme :

* [RSS-Bridge](https://github.com/RSS-Bridge/rss-bridge)
* [Full-Text RSS](https://bitbucket.org/fivefilters/full-text-rss)
