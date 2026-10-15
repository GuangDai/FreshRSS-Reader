# Apparence de l’interface de lecture

La page d’accueil utilise une seule interface de lecture avec 32 palettes intégrées, issues de la configuration daisyUI de MetaCubeXD. Les thèmes sont des préférences de l’application. Ajouter un répertoire sous `p/themes/` ne crée pas de thème de lecture sélectionnable.

## Fichiers concernés

- `app/Services/Reader/Preferences.php` définit les thèmes autorisés, leurs valeurs par défaut et la validation des préférences.
- `p/scripts/reader/appearance/themes.js` et `p/styles/reader/themes.css` contiennent les 32 palettes et leurs valeurs d’origine.
- `p/scripts/reader/appearance/colours.js` calcule les couleurs de texte adaptées aux différents arrière-plans.
- `p/scripts/reader/appearance/choices.js` gère les choix visuels et l’aperçu local.
- `p/scripts/reader/articles/document.js` applique la typographie et les couleurs aux articles isolés.
- `p/styles/vendor/daisyui-LICENSE` contient la licence des valeurs de thème copiées.

Les valeurs doivent rester cohérentes entre JavaScript, CSS et la référence conservée dans `tests/fixtures/themes/`. Aucun environnement d’exécution daisyUI ou Tailwind n’est nécessaire. Les couleurs de texte lisibles sont calculées séparément, sans remplacer les valeurs des palettes.

## Sélection et aperçu

Les sélecteurs de thème fixe, de jour et de nuit affichent d’abord le choix actuel. Une fois ouverts, ils présentent six choix au maximum par page, avec des filtres clair et sombre et une recherche par nom ou description de palette. Les miniatures sont créées uniquement pour les choix visibles. La recherche, les filtres et la pagination ne doivent pas modifier le choix en cours.

Les deux ou trois choix de disposition, de densité et de style de lecture restent directement visibles. La sélection met à jour l’aperçu local ; elle s’applique à la lecture après un enregistrement réussi. L’aperçu agrandi montre les abonnements, la liste des articles et le contenu d’un article. Sur téléphone, ces zones s’affichent séparément. Un échec d’enregistrement doit conserver les modifications, l’article ouvert et la position de lecture.

Les articles restent isolés dans une iframe sans exécution de scripts ni accès de même origine. Les changements d’apparence doivent préserver cette séparation. Utilisez les propriétés CSS logiques pour les dispositions dépendant du sens d’écriture et vérifiez les interfaces de gauche à droite et de droite à gauche.

## Pages d’administration et d’installation

`p/themes/base-theme/` fournit les styles de base fixes des formulaires d’administration et de l’installation. Le chargeur utilise cette base directement, sans tenir compte de l’ancien choix de thème du compte. Les icônes et polices communes restent sous `p/themes/` ; les styles de lecture et de paramètres se trouvent sous `p/styles/reader/`.

Après une modification des styles de base, générez leurs versions RTL avec `npm run rtlcss`. Les styles de lecture utilisent des règles adaptées au sens d’écriture plutôt que des thèmes enregistrés séparément.

## Vérifications

```sh
npm run test:frontend
npm run test:browser:appearance
npm run test:browser:reading
```

Les tests ciblés d’apparence et de lecture utilisent les modèles de l’application et des réponses locales ; ils ne nécessitent pas de compte installé. Consultez le [guide des tests](../../../../tests/README.md) pour les prérequis PHP et navigateur, et la [configuration utilisateur](../../users/05_Configuration.md#personnaliser-la-vue) pour le fonctionnement des paramètres.
