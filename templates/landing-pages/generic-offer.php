<?php

$landing_page = is_array($landing_page ?? null) ? $landing_page : [];
$asset_base_url = rtrim(trim((string) ($landing_page['asset_base_url'] ?? '')), '/');
$resolve_asset_url = static function (string $direct_url, string $base_url, string $path): string {
    $direct_url = trim($direct_url);

    if ($direct_url !== '') {
        return $direct_url;
    }

    $path = ltrim(trim($path), '/');

    if ($base_url === '' || $path === '') {
        return '';
    }

    return $base_url . '/' . $path;
};

$page_title = (string) ($landing_page['page_title'] ?? 'Landing Page');
$background_image_url = $resolve_asset_url(
    (string) ($landing_page['background_image_url'] ?? ''),
    $asset_base_url,
    (string) ($landing_page['background_image_path'] ?? '')
);
$hero_image_url = $resolve_asset_url(
    (string) ($landing_page['hero_image_url'] ?? ''),
    $asset_base_url,
    (string) ($landing_page['hero_image_path'] ?? '')
);
$hero_image_alt = (string) ($landing_page['hero_image_alt'] ?? 'Landing page image');
$cta_href = trim((string) ($landing_page['cta_href'] ?? ($click_to_sms_uri ?? '#')));
$cta_label = (string) ($landing_page['cta_label'] ?? 'CONTINUER');
$terms_url = (string) ($landing_page['terms_url'] ?? '#');
$terms_label = (string) ($landing_page['terms_label'] ?? 'TERMES ET CONDITIONS');
$short_description = (string) ($landing_page['short_description'] ?? '');
$long_description = (string) ($landing_page['long_description'] ?? '');
$price_info = trim((string) ($landing_page['price_info'] ?? ''));
$disclaimer_html = (string) ($landing_page['disclaimer_html'] ?? '');
$keyword_display = trim((string) ($landing_page['keyword_display'] ?? ($landing_page['keyword'] ?? '')));
$shortcode_display = trim((string) ($landing_page['shortcode_display'] ?? ($landing_page['shortcode'] ?? '')));
$price_label = trim((string) ($landing_page['price_label'] ?? ($landing_page['landing_price_label'] ?? '')));
$background_style = $background_image_url !== ''
    ? "url('" . esc_attr($background_image_url) . "') no-repeat center -25px"
    : 'linear-gradient(180deg, #1f1f1f 0%, #2c2c2c 100%)';

if ($price_info === '') {
    $price_parts = [];

    if ($keyword_display !== '' && $shortcode_display !== '') {
        $price_parts[] = 'Activer en envoyant ' . $keyword_display . ' au ' . $shortcode_display;
    }

    if ($price_label !== '') {
        $price_parts[] = $price_label;
    }

    $price_info = implode(' <br> ', $price_parts);
}
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html($page_title); ?></title>
    <style>
        body {
            max-width: 440px;
            font-family: Roboto, sans-serif;
            text-align: center;
            margin: auto;
            padding: 0;
            background-color: #2c2c2c;
        }

        .container {
            position: relative;
            margin: 0 auto;
            padding: 4%;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            overflow: hidden;
        }

        .container::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: <?php echo $background_style; ?>;
            background-size: cover;
            opacity: 0.7;
            z-index: -1;
        }

        .image img {
            width: 100%;
            height: auto;
            max-width: 100%;
        }

        .short-description {
            font-size: 18px;
            font-weight: bold;
            margin: 10px 0;
            color: rgb(226, 226, 226);
        }

        .long-description {
            font-size: 14px;
            margin: 10px 0;
            color: rgb(226, 226, 226);
        }

        .price-info {
            font-size: 16px;
            font-weight: bold;
            color: rgb(226, 226, 226);
            margin: 5px 0;
        }

        .disclaimer {
            font-size: 14px;
            color: rgb(226, 226, 226);
            margin: 50px 0 0 0;
        }

        .button {
            display: inline-block;
            width: 85%;
            height: 50px;
            line-height: 50px;
            background: #9effae;
            color: #2c2c2c;
            text-decoration: none;
            font-size: 22px;
            border-radius: 13px;
            margin: 20px 0;
            text-align: center;
        }

        .button:hover {
            background: #4aa159;
        }

        .terms {
            display: inline-block;
            font-size: 12px;
            color: rgb(236, 14, 14);
            text-decoration: underline;
            margin: 0 0 20px 0;
            padding: 20px 0;
        }

        .terms:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($hero_image_url !== '') : ?>
            <div class="image">
                <img src="<?php echo esc_attr($hero_image_url); ?>" alt="<?php echo esc_attr($hero_image_alt); ?>">
            </div>
        <?php endif; ?>
        <div class="short-description"><?php echo esc_html($short_description); ?></div>
        <div class="long-description"><?php echo esc_html($long_description); ?></div>
        <a href="<?php echo esc_attr($cta_href); ?>" class="button"><?php echo esc_html($cta_label); ?></a>
        <div class="price-info"><?php echo $price_info; ?></div>
        <div class="disclaimer"><?php echo $disclaimer_html; ?></div>
        <br>
        <a href="<?php echo esc_attr($terms_url); ?>" class="terms"><?php echo esc_html($terms_label); ?></a>
    </div>
</body>
</html>
