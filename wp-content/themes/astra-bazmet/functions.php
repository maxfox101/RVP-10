<?php
/**
 * Дочерняя тема Astra «БазМет» — ручные доработки (лаб. работа № 10).
 *
 * Тема Astra + плагины дали каркас сайта.
 * Здесь руками: CPT (изделия/услуги), таксономия типов,
 * шорткоды каталога/героя/заявки, селекты размера на карточке.
 *
 * Связанные файлы в папке inc/:
 *   - product-fields-metabox.php — админка «Варианты выбора»
 *   - strapi-sync.php — синхронизация каталога с Strapi (работа № 9)
 *     по правилу last-write-wins (побеждает более позднее время правки)
 *
 * JS на сайте: order.js (сбор размеров → страница заявки).
 */

/** Подключаем метабокс вариантов изделия в админке (не ACF — свой код). */
require_once get_stylesheet_directory() . '/inc/product-fields-metabox.php';

/**
 * Синхронизация WordPress ↔ Strapi (HTTP API).
 * Меню в админке: «БазМет Sync»; при Save изделия/услуги — push в Strapi.
 */
require_once get_stylesheet_directory() . '/inc/strapi-sync.php';


/**
 * ---------------------------------------------------------------------------
 * Стили и скрипты
 * Родительская Astra + наши style.css; order.js — только на изделии и заявке.
 * ---------------------------------------------------------------------------
 */
add_action('wp_enqueue_scripts', function () {
    // CSS родительской темы Astra
    wp_enqueue_style(
        'astra-parent',
        get_template_directory_uri() . '/style.css',
        [],
        wp_get_theme('astra')->get('Version')
    );
    // Наши стили витрины (сетка каталога, кнопки, форма) — style.css дочерней темы
    wp_enqueue_style(
        'astra-bazmet',
        get_stylesheet_uri(),
        ['astra-parent'],
        wp_get_theme()->get('Version')
    );
    // JS: с селектов размера собирает URL и ведёт на /zayavka/
    if (is_singular('bazmet_product') || is_page('zayavka')) {
        wp_enqueue_script(
            'astra-bazmet-order',
            get_stylesheet_directory_uri() . '/order.js',
            [],
            wp_get_theme()->get('Version'),
            true
        );
    }
});

/**
 * ---------------------------------------------------------------------------
 * CPT и таксономия (Custom Post Types)
 * В админке появляются пункты «Изделия», «Услуги», «Типы метизов».
 * Контент правится без правки PHP — как и полагается в CMS.
 * ---------------------------------------------------------------------------
 */
add_action('init', function () {
    // Тип записей «Изделия» → URL вида /izdeliya/...
    register_post_type('bazmet_product', [
        'labels' => [
            'name'               => 'Изделия',
            'singular_name'      => 'Изделие',
            'add_new'            => 'Добавить',
            'add_new_item'       => 'Добавить изделие',
            'edit_item'          => 'Редактировать изделие',
            'new_item'           => 'Новое изделие',
            'view_item'          => 'Смотреть изделие',
            'search_items'       => 'Искать изделия',
            'not_found'          => 'Изделия не найдены',
            'not_found_in_trash' => 'В корзине изделий нет',
            'menu_name'          => 'Изделия',
        ],
        'public'       => true,
        'show_ui'      => true,
        'show_in_menu' => true,
        'has_archive'  => true,
        'rewrite'      => ['slug' => 'izdeliya'],
        'menu_icon'    => 'dashicons-hammer',
        // title + editor + миниатюра; варианты выбора — отдельный метабокс
        'supports'     => ['title', 'editor', 'thumbnail', 'excerpt'],
        'show_in_rest' => true,
    ]);

    // Тип записей «Услуги» → /uslugi-list/...
    register_post_type('bazmet_service', [
        'labels' => [
            'name'               => 'Услуги',
            'singular_name'      => 'Услуга',
            'add_new'            => 'Добавить',
            'add_new_item'       => 'Добавить услугу',
            'edit_item'          => 'Редактировать услугу',
            'new_item'           => 'Новая услуга',
            'view_item'          => 'Смотреть услугу',
            'search_items'       => 'Искать услуги',
            'not_found'          => 'Услуги не найдены',
            'not_found_in_trash' => 'В корзине услуг нет',
            'menu_name'          => 'Услуги',
        ],
        'public'       => true,
        'show_ui'      => true,
        'show_in_menu' => true,
        'has_archive'  => true,
        'rewrite'      => ['slug' => 'uslugi-list'],
        'menu_icon'    => 'dashicons-admin-tools',
        'supports'     => ['title', 'editor'],
        'show_in_rest' => true,
    ]);

    // Таксономия «Типы метизов» (гайки, болты…) — привязана к изделиям
    register_taxonomy('bazmet_type', 'bazmet_product', [
        'labels' => [
            'name'          => 'Типы метизов',
            'singular_name' => 'Тип',
            'add_new_item'  => 'Добавить тип',
            'edit_item'     => 'Редактировать тип',
        ],
        'public'       => true,
        'show_ui'      => true,
        'hierarchical' => true,
        'rewrite'      => ['slug' => 'tip-metiza'],
        'show_in_rest' => true,
    ]);
});

/**
 * Классический редактор для изделий и услуг.
 * Gutenberg в части окружений не поднимается («нужен JavaScript») —
 * тогда форма создания выглядит пустой. Classic Editor всегда показывает
 * заголовок, текст и метабокс «Варианты выбора».
 */
add_filter('use_block_editor_for_post_type', function ($use, $post_type) {
    if (in_array($post_type, ['bazmet_product', 'bazmet_service'], true)) {
        return false;
    }
    return $use;
}, 10, 2);

/**
 * ---------------------------------------------------------------------------
 * Шорткод [bazmet_catalog] — сетка карточек изделий
 * Вставить на страницу «Каталог» в редакторе WP.
 * Опционально: [bazmet_catalog type="gayki" limit="50"]
 * Стили сетки (4 колонки) — в style.css (.bazmet-catalog).
 * ---------------------------------------------------------------------------
 */
add_shortcode('bazmet_catalog', function ($atts) {
    $atts = shortcode_atts(['type' => '', 'limit' => '50'], $atts);
    $args = [
        'post_type' => 'bazmet_product',
        'posts_per_page' => (int) $atts['limit'],
        'post_status' => 'publish',
        'orderby' => 'title',
        'order' => 'ASC',
    ];
    // Фильтр по типу метиза, если передан атрибут type
    if ($atts['type'] !== '') {
        $args['tax_query'] = [[
            'taxonomy' => 'bazmet_type',
            'field' => 'slug',
            'terms' => sanitize_title($atts['type']),
        ]];
    }

    $q = new WP_Query($args);
    if (!$q->have_posts()) {
        return '<p>Пока нет изделий в каталоге.</p>';
    }

    ob_start();
    echo '<div class="bazmet-catalog">';
    while ($q->have_posts()) {
        $q->the_post();
        $terms = get_the_terms(get_the_ID(), 'bazmet_type');
        $type = ($terms && !is_wp_error($terms)) ? $terms[0]->name : '';
        echo '<article class="bazmet-card">';
        if (has_post_thumbnail()) {
            echo '<a class="bazmet-card-img" href="' . esc_url(get_permalink()) . '">';
            echo get_the_post_thumbnail(get_the_ID(), 'medium');
            echo '</a>';
        }
        echo '<h3><a href="' . esc_url(get_permalink()) . '">' . esc_html(get_the_title()) . '</a></h3>';
        if ($type) {
            echo '<div class="bazmet-meta">Тип: ' . esc_html($type) . '</div>';
        }
        echo '<p>' . esc_html(wp_trim_words(get_the_excerpt() ?: get_the_content(), 16)) . '</p>';
        echo '<p><a class="bazmet-btn" href="' . esc_url(get_permalink()) . '">Выбрать размер</a></p>';
        echo '</article>';
    }
    echo '</div>';
    wp_reset_postdata();
    return ob_get_clean();
});

/**
 * ---------------------------------------------------------------------------
 * Шорткод [bazmet_services] — список услуг с кнопкой на /zayavka/?service=…
 * ---------------------------------------------------------------------------
 */
add_shortcode('bazmet_services', function () {
    $q = new WP_Query([
        'post_type' => 'bazmet_service',
        'posts_per_page' => 20,
        'post_status' => 'publish',
        'orderby' => 'title',
        'order' => 'ASC',
    ]);
    if (!$q->have_posts()) {
        return '<p>Услуги пока не добавлены.</p>';
    }
    ob_start();
    echo '<div class="bazmet-services">';
    while ($q->have_posts()) {
        $q->the_post();
        echo '<article class="bazmet-service">';
        echo '<h3>' . esc_html(get_the_title()) . '</h3>';
        echo '<p>' . esc_html(wp_strip_all_tags(get_the_content())) . '</p>';
        $order = add_query_arg(
            ['service' => get_the_title()],
            home_url('/zayavka/')
        );
        echo '<p><a class="bazmet-btn" href="' . esc_url($order) . '">Оставить заявку</a></p>';
        echo '</article>';
    }
    echo '</div>';
    wp_reset_postdata();
    return ob_get_clean();
});

/**
 * ---------------------------------------------------------------------------
 * Шорткод [bazmet_hero] — баннер на главной
 * Пример: [bazmet_hero title="…" text="…"]
 * ---------------------------------------------------------------------------
 */
add_shortcode('bazmet_hero', function ($atts) {
    $atts = shortcode_atts([
        'title' => 'ООО «БазМет» — надёжный производитель с многолетней историей.',
        'text' => 'Качество. Точность. Стабильность. Каталог ГОСТ / ОСТ / нормалей.',
    ], $atts);
    return '<section class="bazmet-hero"><h1>' . esc_html($atts['title']) . '</h1><p>'
        . esc_html($atts['text']) . '</p></section>';
});

/**
 * ---------------------------------------------------------------------------
 * Селекты на карточке изделия (материал, диаметр, длина, покрытие)
 * Данные берутся из meta `_bazmet_fields` — их задаёт метабокс в админке
 * (inc/product-fields-metabox.php). Меняешь список в админке → обновляешь
 * страницу изделия → новые option в <select>.
 * ---------------------------------------------------------------------------
 */
function bazmet_render_product_options($post_id) {
    $raw = get_post_meta($post_id, '_bazmet_fields', true);
    $fields = json_decode($raw ?: '[]', true);
    if (!is_array($fields) || !$fields) {
        return '';
    }

    $title = get_the_title($post_id);
    $order_url = home_url('/zayavka/');

    ob_start();
    echo '<div class="bazmet-options" data-order-base="' . esc_url($order_url) . '">';
    echo '<h3>Варианты выбора</h3>';
    echo '<form class="bazmet-options-form" id="bazmet-options-form">';
    echo '<input type="hidden" name="product" value="' . esc_attr($title) . '">';
    foreach ($fields as $field) {
        $id = sanitize_key($field['id'] ?? '');
        $label = $field['label'] ?? $id;
        $options = $field['options'] ?? [];
        if (!$id || !$options) {
            continue;
        }
        echo '<div class="bazmet-field">';
        echo '<label for="bazmet-' . esc_attr($id) . '">' . esc_html($label) . '</label>';
        echo '<select id="bazmet-' . esc_attr($id) . '" name="' . esc_attr($id) . '" required>';
        echo '<option value="">Выберите ' . esc_html(mb_strtolower($label)) . '</option>';
        foreach ($options as $opt) {
            $value = $opt['value'] ?? '';
            $opt_label = $opt['label'] ?? $value;
            echo '<option value="' . esc_attr($value) . '">' . esc_html($opt_label) . '</option>';
        }
        echo '</select></div>';
    }
    echo '<button type="submit" class="bazmet-btn">Оставить заявку на выбранный размер</button>';
    echo '</form></div>';
    return ob_get_clean();
}

/** Дописываем блок селектов в конец содержимого одиночного изделия. */
add_filter('the_content', function ($content) {
    if (!is_singular('bazmet_product') || !in_the_loop() || !is_main_query()) {
        return $content;
    }
    static $done = false;
    if ($done) {
        return $content;
    }
    $done = true;
    return $content . bazmet_render_product_options(get_the_ID());
});

/**
 * ---------------------------------------------------------------------------
 * Шорткод [bazmet_order] — страница /zayavka/
 * Читает product / service / размеры из URL (после выбора на карточке),
 * показывает сводку, принимает POST, шлёт письмо и сохраняет private-запись.
 * ---------------------------------------------------------------------------
 */
add_shortcode('bazmet_order', function () {
    static $order_done = false;
    if ($order_done) {
        return '';
    }
    $order_done = true;

    // Параметры из query string (?product=…&material=…&diameter=…)
    $product = isset($_GET['product']) ? sanitize_text_field(wp_unslash($_GET['product'])) : '';
    $service = isset($_GET['service']) ? sanitize_text_field(wp_unslash($_GET['service'])) : '';
    if ($product === '' && $service !== '') {
        $product = $service;
    }
    $keys = ['material', 'diameter', 'length', 'coating', 'size', 'type'];
    $labels = [
        'material' => 'Материал',
        'diameter' => 'Диаметр резьбы',
        'length' => 'Длина',
        'coating' => 'Покрытие',
        'size' => 'Размер',
        'type' => 'Тип',
    ];
    $picked = [];
    foreach ($keys as $key) {
        if (!empty($_GET[$key])) {
            $picked[$key] = sanitize_text_field(wp_unslash($_GET[$key]));
        }
    }

    // Текст «Выбранная конфигурация» над формой
    $summary_lines = [];
    if ($product) {
        if (!empty($_GET['service']) && empty($_GET['product'])) {
            $summary_lines[] = 'Услуга: ' . $product;
        } else {
            $summary_lines[] = 'Изделие: ' . $product;
        }
    }
    foreach ($picked as $key => $val) {
        $summary_lines[] = ($labels[$key] ?? $key) . ': ' . $val;
    }
    $summary = implode("\n", $summary_lines);

    $sent = false;
    $error = '';
    // Обработка отправки формы
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bazmet_order_nonce'])) {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bazmet_order_nonce'])), 'bazmet_order')) {
            $error = 'Ошибка безопасности. Обновите страницу.';
        } else {
            $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
            $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
            $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
            $description = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));
            $config = sanitize_textarea_field(wp_unslash($_POST['config'] ?? ''));

            if ($name === '' || $phone === '' || $email === '') {
                $error = 'Заполните имя, телефон и e-mail.';
            } elseif (!is_email($email)) {
                $error = 'Введите корректный e-mail.';
            } else {
                $body = "Заявка БазМет\n\nИмя: {$name}\nТелефон: {$phone}\nE-mail: {$email}\n\nКонфигурация:\n{$config}\n\nКомментарий:\n{$description}";
                wp_mail(get_option('admin_email'), 'Заявка БазМет: ' . ($product ?: 'сайт'), $body);
                // Дублируем заявку как private-запись в «Записи» (видно в админке)
                wp_insert_post([
                    'post_type' => 'post',
                    'post_status' => 'private',
                    'post_title' => 'Заявка: ' . ($product ?: $name) . ' — ' . wp_date('d.m.Y H:i'),
                    'post_content' => $body,
                ]);
                $sent = true;
            }
        }
    }

    ob_start();
    if ($sent) {
        echo '<div class="bazmet-order-success"><p>Спасибо! Заявка отправлена. Мы свяжемся с вами по указанному телефону и e-mail.</p>';
        echo '<p><a href="' . esc_url(home_url('/katalog/')) . '">Вернуться в каталог</a></p></div>';
        return ob_get_clean();
    }

    echo '<div class="bazmet-order">';
    if ($summary) {
        echo '<div class="bazmet-order-summary"><h3>Выбранная конфигурация</h3><pre>' . esc_html($summary) . '</pre></div>';
    } else {
        echo '<p class="bazmet-order-hint">Выберите изделие в <a href="' . esc_url(home_url('/katalog/')) . '">каталоге</a>, укажите размер и нажмите «Оставить заявку» — сюда подставится конфигурация.</p>';
    }
    if ($error) {
        echo '<p class="bazmet-order-error">' . esc_html($error) . '</p>';
    }
    echo '<form method="post" class="bazmet-order-form">';
    echo '<input type="hidden" name="bazmet_order_nonce" value="' . esc_attr(wp_create_nonce('bazmet_order')) . '">';
    echo '<input type="hidden" name="config" value="' . esc_attr($summary) . '">';
    echo '<div class="bazmet-field"><label for="bazmet-name">Имя *</label>';
    echo '<input id="bazmet-name" name="name" type="text" required value="' . esc_attr(isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '') . '"></div>';
    echo '<div class="bazmet-field"><label for="bazmet-phone">Телефон *</label>';
    echo '<input id="bazmet-phone" name="phone" type="tel" required placeholder="+7 (___) ___ - __ - __" value="' . esc_attr(isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '') . '"></div>';
    echo '<div class="bazmet-field"><label for="bazmet-email">E-mail *</label>';
    echo '<input id="bazmet-email" name="email" type="email" required value="' . esc_attr(isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '') . '"></div>';
    echo '<div class="bazmet-field"><label for="bazmet-desc">Комментарий</label>';
    echo '<textarea id="bazmet-desc" name="description" rows="4">' . esc_textarea(isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '') . '</textarea></div>';
    echo '<button type="submit" class="bazmet-btn">Отправить заявку</button>';
    echo '</form></div>';
    return ob_get_clean();
});


/**
 * ---------------------------------------------------------------------------
 * Мелочи темы Astra: русские подписи «Назад/Далее», копирайт, скрыть meta
 * ---------------------------------------------------------------------------
 */
add_filter('astra_single_post_navigation_enabled', '__return_true');
add_filter('astra_default_strings', function ($defaults, $context = null) {
    if (!is_array($defaults)) {
        return $defaults;
    }
    $defaults['string-previous-text'] = 'Назад';
    $defaults['string-next-text'] = 'Далее';
    $defaults['string-blog-navigation-previous'] = '<span class="ast-left-arrow" aria-hidden="true">&larr;</span> Назад';
    $defaults['string-blog-navigation-next'] = 'Далее <span class="ast-right-arrow" aria-hidden="true">&rarr;</span>';
    $defaults['string-single-navigation-previous'] = '<span class="ast-left-arrow" aria-hidden="true">&larr;</span> Назад';
    $defaults['string-single-navigation-next'] = 'Далее <span class="ast-right-arrow" aria-hidden="true">&rarr;</span>';
    return $defaults;
}, 20, 2);

add_filter('gettext', function ($translated, $text, $domain) {
    if ($domain !== 'astra') {
        return $translated;
    }
    $map = [
        'Previous' => 'Назад',
        'Next' => 'Далее',
        'Previous %s' => 'Назад',
        'Next %s' => 'Далее',
    ];
    return $map[$text] ?? $translated;
}, 20, 3);


/** Русский текст в подвале копирайта Astra. */
add_filter('astra_footer_copyright', function ($content) {
    $content = str_replace('Powered by', 'Работает на теме', $content);
    $content = str_replace('Copyright', '©', $content);
    return $content;
}, 20);


/** Скрыть «By / дата» на одиночных изделиях и услугах. */
add_filter('astra_single_post_meta', function ($html) {
    if (is_singular(['bazmet_product', 'bazmet_service'])) {
        return '';
    }
    return $html;
});
add_filter('astra_get_option_ast-dynamic-single-bazmet_product-metadata', function () {
    return [];
});

/** Пометка в футере для защиты / скрина «это работа на дочерней теме». */
add_action('wp_footer', function () {
    echo '<div class="bazmet-footer-note">БазМет · работа № 10 (тема Astra + плагины + ручной код дочерней темы)</div>';
});
