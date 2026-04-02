<?php

$landing_page = array_merge([
    'page_title' => 'Joyplay',
    'asset_base_url' => 'https://kiwimobile.de/wp-content/uploads/2025/05',
    'background_image_path' => 'Joyplay_background_fullscreen_vertical.png',
    'hero_image_path' => 'FR-Joyplay_LandingPage_Overview_Collage.png',
    'hero_image_alt' => 'Joyplay landing page image',
    'cta_label' => 'CONTINUER ET PAYER',
    'terms_url' => '#',
    'terms_label' => 'TERMES ET CONDITIONS',
    'keyword' => 'JPLAY',
    'shortcode' => '84072',
    'price_label' => '4,50 € / SMS + prix d\'un SMS',
    'short_description' => 'Grand plaisir de jeu sans téléchargement',
    'long_description' => 'Joyplay est un service qui propose un accès illimité à plus de 38 jeux pour une durée d\'un mois',
    'disclaimer_html' => 'Un portail mobile pour des jeux HTML5 populaires - aucun téléchargement requis, les utilisateurs peuvent jouer directement dans leur navigateur. Après l\'achat de MyJoyplay, l\'utilisateur a la possibilité de jouer à tous les jeux en ligne en illimité pendant 30 jours. Le prix du service est de 4,50 €. Il s\'agit de frais uniques. L\'activation du service : veuillez envoyer un SMS avec le texte JPLAY au 84072. En activant le service, vous confirmez que vous avez lu le règlement et que vous acceptez de recevoir gratuitement des informations de commercialisation et de publicité du fournisseur de service. Les frais ne comprennent pas l\'utilisation de l\'Internet mobile. Aide : 0170700354 du lundi au vendredi de 9h00 à 17h00 ou envoyez un e-mail à <a href="mailto:myjoyplay.fr@silverlines.info" style="color: red;">myjoyplay.fr@silverlines.info</a>. Fournisseur de services kiwi mobile GmbH, Kokkolastr. 5, 40882 Ratingen, Allemagne.',
], is_array($landing_page ?? null) ? $landing_page : []);

include __DIR__ . '/generic-offer.php';
