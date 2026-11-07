<?php
/**
 * ============================================================================
 * БазМет: синхронизация WordPress ↔ Strapi (работа № 10 + связь с № 9)
 * ============================================================================
 *
 * ЧТО ЭТО
 * -------
 * WordPress хранит каталог (изделия, услуги) у себя.
 * Strapi — отдельная CMS с REST API (http://127.0.0.1:1337).
 * Этот файл связывает их: данные каталога ездят туда-сюда по HTTP.
 *
 * ДВУСТОРОННЯЯ СВЯЗЬ (Strapi ↔ WordPress)
 * --------------------------------------
 * Прямая:  правка в WordPress  → сразу HTTP PUT/POST в Strapi.
 * Обратная: правка в Strapi    → через ~2 сек webhook → подтягиваем в WP.
 * Полный sync (кнопка / cron): сравниваем время и гоняем в обе стороны.
 *
 * ПРАВИЛО «LAST WRITE WINS» (побеждает последнее изменение)
 * --------------------------------------------------------
 *   - WordPress: meta _bazmet_edited_at (только ручной Save в админке WP)
 *   - Strapi: поле updatedAt из API
 * Кто новее — тот «правда». Старую сторону перезаписываем.
 * post_modified WP НЕ используем (после pull он = «сейчас» и ломал связь).
 *
 * КОГДА ЗАПУСКАЕТСЯ
 * -----------------
 * 1) Save изделия/услуги в WP → push в Strapi.
 * 2) Удаление/корзина в WP → DELETE в Strapi (и наоборот через sync).
 * 3) Save/Publish/Delete в Strapi → webhook → sync в WP.
 * 4) Кнопка «Синхронизировать сейчас» → полный обход туда и обратно.
 * 5) WP-Cron каждые 5 минут — тихий полный sync.
 *
 * УДАЛЕНИЕ
 * --------
 * Если запись была связана (есть documentId), но пропала из Strapi —
 * удаляем и в WordPress (иначе sync снова создал бы её в Strapi).
 * Удалили в WP → сразу DELETE в Strapi.
 *
 * СЕКРЕТ ДЛЯ WEBHOOK
 * ------------------
 * Заголовок X-Bazmet-Sync-Secret или параметр ?secret=
 * Значение по умолчанию: bazmet-local-sync
 * (можно задать константу BAZMET_SYNC_SECRET в wp-config.php).
 *
 * КЛЮЧ СВЯЗИ ЗАПИСЕЙ
 * ------------------
 * Изделия: sku в Strapi = ярлык (post_name) в WordPress, например nut-gost-5915.
 * Услуги:  совпадение по заголовку (title); documentId Strapi пишем в meta.
 *
 * НАСТРОЙКА URL
 * -------------
 * Константа BAZMET_STRAPI_URL в wp-config.php (по умолчанию :1337).
 *
 * ВАЖНО ДЛЯ НОВИЧКА
 * -----------------
 * PHP здесь, не JavaScript. Браузер этот файл не выполняет —
 * его подключает WordPress на сервере при открытии админки/сайта.
 */

if (!defined('ABSPATH')) {
    exit; // запрет прямого открытия файла в браузере
}

/**
 * Базовый адрес Strapi без слэша в конце.
 * Пример: http://127.0.0.1:1337
 *
 * @return string
 */
function bazmet_strapi_base_url() {
    if (defined('BAZMET_STRAPI_URL') && BAZMET_STRAPI_URL) {
        return untrailingslashit(BAZMET_STRAPI_URL);
    }
    return 'http://127.0.0.1:1337';
}

/**
 * Соответствие ярлыков типа метиза WP → slug категории в Strapi.
 * В WP: nut, bolt…  В Strapi: nuts, bolts…
 *
 * @return array<string,string>
 */
function bazmet_type_slug_to_strapi() {
    return [
        'nut' => 'nuts',
        'bolt' => 'bolts',
        'screw' => 'screws',
        'rivet' => 'rivets',
        'washer' => 'washers',
        // на случай, если уже записали «множественный» ярлык
        'nuts' => 'nuts',
        'bolts' => 'bolts',
        'screws' => 'screws',
        'rivets' => 'rivets',
        'washers' => 'washers',
    ];
}

/**
 * Обратное соответствие: slug Strapi → ярлык термина WP.
 *
 * @return array<string,string>
 */
function bazmet_strapi_slug_to_type() {
    return [
        'nuts' => 'nut',
        'bolts' => 'bolt',
        'screws' => 'screw',
        'rivets' => 'rivet',
        'washers' => 'washer',
    ];
}

/**
 * Переводит строку времени (ISO из Strapi или MySQL GMT из WP) в unix-секунды.
 * Нужно, чтобы сравнивать «кто новее» простым числом.
 *
 * @param string $value Дата/время
 * @return int Секунды с 1970-01-01; 0 если разобрать не удалось
 */
function bazmet_time_to_unix($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }
    $ts = strtotime($value);
    return $ts ? (int) $ts : 0;
}

/**
 * Запоминает момент РУЧНОЙ правки в админке WordPress.
 * Пишется только при Save человеком — НЕ при pull из Strapi.
 * Иначе post_modified после pull = «сейчас» и WP всегда кажется новее → затирает Strapi.
 *
 * @param int $post_id
 */
function bazmet_mark_wp_edited($post_id) {
    update_post_meta((int) $post_id, '_bazmet_edited_at', gmdate('c'));
}

/**
 * HTTP-запрос к Strapi (GET / POST / PUT / DELETE).
 * Обёртка над wp_remote_request — удобнее, чем писать curl руками.
 *
 * @param string               $method GET|POST|PUT|DELETE
 * @param string               $path   Путь вида /api/products?...
 * @param array<string,mixed>|null $body Тело JSON (для POST/PUT) или null
 * @return array{ok:bool,code:int,data:mixed,error:string}
 */
function bazmet_strapi_request($method, $path, $body = null) {
    $url = bazmet_strapi_base_url() . $path;
    $args = [
        'method'  => strtoupper($method),
        'timeout' => 20,
        'headers' => [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ],
    ];
    if ($body !== null) {
        // wp_json_encode — безопасный JSON с Unicode (русские буквы не ломаются)
        $args['body'] = wp_json_encode($body);
    }

    $response = wp_remote_request($url, $args);
    if (is_wp_error($response)) {
        return [
            'ok'    => false,
            'code'  => 0,
            'data'  => null,
            'error' => $response->get_error_message(),
        ];
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $raw  = wp_remote_retrieve_body($response);
    $data = json_decode($raw, true);

    return [
        'ok'    => $code >= 200 && $code < 300,
        'code'  => $code,
        'data'  => $data,
        'error' => ($code >= 200 && $code < 300) ? '' : ('HTTP ' . $code . ': ' . mb_substr($raw, 0, 200)),
    ];
}

/**
 * Проверка, что Strapi отвечает (для экрана админки).
 *
 * @return bool
 */
function bazmet_strapi_is_up() {
    $res = bazmet_strapi_request('GET', '/api/products?pagination[pageSize]=1');
    return $res['ok'];
}

/**
 * Достаёт все элементы коллекции Strapi с пагинацией.
 * Strapi по умолчанию отдаёт не все записи сразу — листаем страницы.
 *
 * @param string $plural Имя коллекции во мн. числе: products | service-items | categories
 * @param string $query  Доп. query-строка (без ведущего ?)
 * @return array{ok:bool,items:array<int,array>,error:string}
 */
function bazmet_strapi_fetch_all($plural, $query = '') {
    $items = [];
    $page  = 1;
    $page_size = 100;

    do {
        $path = '/api/' . $plural . '?pagination[page]=' . $page
            . '&pagination[pageSize]=' . $page_size
            . '&pagination[withCount]=true';
        if ($query !== '') {
            $path .= '&' . ltrim($query, '&');
        }
        $res = bazmet_strapi_request('GET', $path);
        if (!$res['ok']) {
            return ['ok' => false, 'items' => [], 'error' => $res['error']];
        }
        $chunk = $res['data']['data'] ?? [];
        if (!is_array($chunk)) {
            $chunk = [];
        }
        foreach ($chunk as $row) {
            $items[] = $row;
        }
        $page_count = (int) ($res['data']['meta']['pagination']['pageCount'] ?? 1);
        $page++;
    } while ($page <= $page_count);

    return ['ok' => true, 'items' => $items, 'error' => ''];
}

/**
 * Нормализует одну запись Strapi в плоский массив атрибутов + documentId + updatedAt.
 * В Strapi 5 поля лежат либо в корне, либо во вложенном attributes — поддерживаем оба вида.
 *
 * @param array<string,mixed> $row Сырой элемент из API
 * @return array<string,mixed>
 */
function bazmet_strapi_normalize_row($row) {
    if (!is_array($row)) {
        return [];
    }
    $attrs = isset($row['attributes']) && is_array($row['attributes'])
        ? $row['attributes']
        : $row;
    $out = $attrs;
    $out['documentId'] = $row['documentId'] ?? ($attrs['documentId'] ?? '');
    $out['id']         = $row['id'] ?? ($attrs['id'] ?? null);
    $out['updatedAt']  = $attrs['updatedAt'] ?? ($row['updatedAt'] ?? '');
    $out['createdAt']  = $attrs['createdAt'] ?? ($row['createdAt'] ?? '');
    return $out;
}

/**
 * Ищет documentId категории Strapi по slug (nuts, bolts…).
 * Результат кэшируем в static, чтобы не дергать API на каждое изделие.
 *
 * @param string $strapi_slug
 * @return string documentId или пустая строка
 */
function bazmet_strapi_category_document_id($strapi_slug) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $res = bazmet_strapi_fetch_all('categories');
        if ($res['ok']) {
            foreach ($res['items'] as $row) {
                $n = bazmet_strapi_normalize_row($row);
                if (!empty($n['slug']) && !empty($n['documentId'])) {
                    $cache[$n['slug']] = $n['documentId'];
                }
            }
        }
    }
    return $cache[$strapi_slug] ?? '';
}

/**
 * Проверяет, что slug/sku безопасен для URL (латиница, без %d0…).
 *
 * @param string $slug
 * @return bool
 */
function bazmet_slug_is_safe($slug) {
    $slug = (string) $slug;
    if ($slug === '' || strpos($slug, '%') !== false) {
        return false;
    }
    return (bool) preg_match('/^[a-z0-9][a-z0-9\-_]*$/i', $slug);
}

/**
 * Строит безопасный ярлык изделия для WP и поле sku в Strapi.
 * Кириллический sanitize_title даёт %d0%be… — такие ссылки на сайте не открываются.
 *
 * @param string $title
 * @param string $document_id
 * @param string $preferred  Уже известный sku, если он «чистый»
 * @return string
 */
function bazmet_make_safe_product_slug($title, $document_id = '', $preferred = '') {
    $preferred = trim((string) $preferred);
    if (bazmet_slug_is_safe($preferred)) {
        return strtolower($preferred);
    }

    // remove_accents почти не трогает кириллицу → всё равно проверим результат
    $from_title = sanitize_title(remove_accents((string) $title));
    if (bazmet_slug_is_safe($from_title)) {
        return strtolower($from_title);
    }

    $doc = preg_replace('/[^a-z0-9]/i', '', (string) $document_id);
    if ($doc !== '') {
        return 'item-' . strtolower(substr($doc, 0, 16));
    }

    return 'item-' . substr(md5((string) $title . microtime()), 0, 10);
}

/**
 * SKU изделия: ярлык записи WP (post_name). Если битый/пустой — безопасный fallback.
 *
 * @param int $post_id
 * @return string
 */
function bazmet_product_sku($post_id) {
    $sku = get_post_field('post_name', $post_id);
    if (bazmet_slug_is_safe($sku)) {
        return $sku;
    }
    $doc = get_post_meta($post_id, '_bazmet_strapi_document_id', true);
    return bazmet_make_safe_product_slug(get_the_title($post_id), $doc, $sku);
}

/**
 * Время правки записи WP в GMT (для сравнения со Strapi).
 *
 * @param int $post_id
 * @return int unix time
 */
/**
 * Время «логической» правки в WordPress для last-write-wins.
 * Только meta _bazmet_edited_at (ставится при Save в админке WP).
 * Если meta нет → 0: не используем post_modified (он прыгает при каждом pull
 * и ложно делает WP «новее», из‑за чего правки Strapi затирались).
 *
 * @param int $post_id
 * @return int
 */
function bazmet_wp_modified_unix($post_id) {
    $edited = get_post_meta($post_id, '_bazmet_edited_at', true);
    if (is_string($edited) && $edited !== '') {
        return bazmet_time_to_unix($edited);
    }
    return 0;
}

/**
 * Собирает тело product для POST/PUT в Strapi из записи WordPress.
 *
 * @param int $post_id
 * @return array<string,mixed>
 */
function bazmet_wp_product_to_strapi_data($post_id) {
    $sku   = bazmet_product_sku($post_id);
    $title = get_the_title($post_id);
    $desc  = wp_strip_all_tags(get_post_field('post_content', $post_id));

    // Варианты выбора из метабокса (JSON) → fields + selectFields для Strapi
    $fields_raw = get_post_meta($post_id, '_bazmet_fields', true);
    $fields     = json_decode($fields_raw ?: '[]', true);
    if (!is_array($fields)) {
        $fields = [];
    }
    $select_fields = [];
    foreach ($fields as $field) {
        $select_fields[] = [
            'fieldId' => $field['id'] ?? '',
            'label'   => $field['label'] ?? '',
            'options' => array_map(function ($opt) {
                return [
                    'value' => $opt['value'] ?? '',
                    'label' => $opt['label'] ?? ($opt['value'] ?? ''),
                ];
            }, $field['options'] ?? []),
        ];
    }

    // Тип метиза → категория Strapi
    $terms = get_the_terms($post_id, 'bazmet_type');
    $type_slug = ($terms && !is_wp_error($terms) && $terms) ? $terms[0]->slug : '';
    $map = bazmet_type_slug_to_strapi();
    $strapi_cat_slug = $map[$type_slug] ?? '';
    $category_id = $strapi_cat_slug ? bazmet_strapi_category_document_id($strapi_cat_slug) : '';

    $data = [
        'title'       => $title,
        'sku'         => $sku,
        'description' => $desc,
        'fields'      => $fields,
        'selectFields'=> $select_fields,
        'inStock'     => true,
    ];
    if ($category_id) {
        // В Strapi 5 связь manyToOne задаётся documentId категории
        $data['category'] = $category_id;
    }
    return $data;
}

/**
 * Собирает тело service-item для Strapi из записи WP.
 *
 * @param int $post_id
 * @return array<string,mixed>
 */
function bazmet_wp_service_to_strapi_data($post_id) {
    return [
        'title' => get_the_title($post_id),
        'text'  => wp_strip_all_tags(get_post_field('post_content', $post_id)),
    ];
}

/**
 * Ищет изделие в Strapi по sku.
 *
 * @param string $sku
 * @return array<string,mixed>|null Нормализованная запись или null
 */
function bazmet_strapi_find_product_by_sku($sku) {
    $path = '/api/products?filters[sku][$eq]=' . rawurlencode($sku)
        . '&pagination[pageSize]=1&populate=category';
    $res = bazmet_strapi_request('GET', $path);
    if (!$res['ok']) {
        return null;
    }
    $list = $res['data']['data'] ?? [];
    if (!$list) {
        return null;
    }
    return bazmet_strapi_normalize_row($list[0]);
}

/**
 * Ищет услугу в Strapi по точному title.
 *
 * @param string $title
 * @return array<string,mixed>|null
 */
function bazmet_strapi_find_service_by_title($title) {
    $path = '/api/service-items?filters[title][$eq]=' . rawurlencode($title)
        . '&pagination[pageSize]=1';
    $res = bazmet_strapi_request('GET', $path);
    if (!$res['ok']) {
        return null;
    }
    $list = $res['data']['data'] ?? [];
    if (!$list) {
        return null;
    }
    return bazmet_strapi_normalize_row($list[0]);
}

/**
 * Достаёт изделие Strapi по documentId (GET /api/products/:documentId).
 *
 * @param string $document_id
 * @return array<string,mixed>|null
 */
function bazmet_strapi_find_product_by_document_id($document_id) {
    $document_id = trim((string) $document_id);
    if ($document_id === '') {
        return null;
    }
    $res = bazmet_strapi_request(
        'GET',
        '/api/products/' . rawurlencode($document_id) . '?populate=category'
    );
    if (!$res['ok'] || empty($res['data']['data'])) {
        return null;
    }
    return bazmet_strapi_normalize_row($res['data']['data']);
}

/**
 * PUT изделия в Strapi по documentId. В Strapi 5 create/update уже публикуют
 * (publishedAt ставится сам), отдельный /actions/publish часто даёт 405 — не используем.
 *
 * @param string               $document_id
 * @param array<string,mixed>  $data
 * @return array{ok:bool,code:int,data:mixed,error:string}
 */
function bazmet_strapi_put_product($document_id, $data) {
    return bazmet_strapi_request(
        'PUT',
        '/api/products/' . rawurlencode($document_id),
        ['data' => $data]
    );
}

/**
 * Удаляет изделие в Strapi по documentId (DELETE /api/products/:id).
 *
 * @param string $document_id
 * @return array{ok:bool,code:int,data:mixed,error:string}
 */
function bazmet_strapi_delete_product($document_id) {
    return bazmet_strapi_request(
        'DELETE',
        '/api/products/' . rawurlencode($document_id)
    );
}

/**
 * Удаляет услугу в Strapi по documentId.
 *
 * @param string $document_id
 * @return array{ok:bool,code:int,data:mixed,error:string}
 */
function bazmet_strapi_delete_service($document_id) {
    return bazmet_strapi_request(
        'DELETE',
        '/api/service-items/' . rawurlencode($document_id)
    );
}

/**
 * Удаляет связанную запись из Strapi при удалении/корзине в WordPress.
 *
 * @param int $post_id
 * @return array{ok:bool,message:string}
 */
function bazmet_delete_remote_for_wp_post($post_id) {
    $post = get_post($post_id);
    if (!$post || !in_array($post->post_type, ['bazmet_product', 'bazmet_service'], true)) {
        return ['ok' => true, 'message' => 'не изделие/услуга'];
    }

    $doc_id = get_post_meta($post_id, '_bazmet_strapi_document_id', true);
    $label  = $post->post_title ?: ('#' . $post_id);

    if ($post->post_type === 'bazmet_product') {
        if (!$doc_id) {
            $found = bazmet_strapi_find_product_by_sku(bazmet_product_sku($post_id));
            $doc_id = $found['documentId'] ?? '';
        }
        if (!$doc_id) {
            return ['ok' => true, 'message' => "«{$label}» в Strapi не найдено — нечего удалять"];
        }
        set_transient('bazmet_ignore_strapi_webhook', 1, 20);
        $res = bazmet_strapi_delete_product($doc_id);
    } else {
        if (!$doc_id) {
            // Услуги часто связаны только по title
            $path = '/api/service-items?filters[title][$eq]=' . rawurlencode($post->post_title)
                . '&pagination[pageSize]=1';
            $find = bazmet_strapi_request('GET', $path);
            $row  = ($find['ok'] && !empty($find['data']['data'][0]))
                ? bazmet_strapi_normalize_row($find['data']['data'][0])
                : null;
            $doc_id = $row['documentId'] ?? '';
        }
        if (!$doc_id) {
            return ['ok' => true, 'message' => "услуга «{$label}» в Strapi не найдена"];
        }
        set_transient('bazmet_ignore_strapi_webhook', 1, 20);
        $res = bazmet_strapi_delete_service($doc_id);
    }

    // 404 = уже удалено в Strapi — тоже успех
    if ($res['ok'] || (int) $res['code'] === 404) {
        delete_post_meta($post_id, '_bazmet_strapi_document_id');
        return ['ok' => true, 'message' => "«{$label}» удалено в Strapi (documentId {$doc_id})"];
    }
    return ['ok' => false, 'message' => "Не удалось удалить «{$label}» в Strapi: " . $res['error']];
}

/**
 * Хук: запись в корзину или полное удаление в WP → удалить и в Strapi.
 *
 * @param int $post_id
 */
function bazmet_on_wp_trash_or_delete($post_id) {
    if (bazmet_is_syncing()) {
        return;
    }
    $post = get_post($post_id);
    if (!$post || !in_array($post->post_type, ['bazmet_product', 'bazmet_service'], true)) {
        return;
    }
    $r = bazmet_delete_remote_for_wp_post($post_id);
    if (is_admin()) {
        set_transient(
            'bazmet_sync_notice_' . get_current_user_id(),
            $r,
            60
        );
    }
}
add_action('wp_trash_post', 'bazmet_on_wp_trash_or_delete');
add_action('before_delete_post', 'bazmet_on_wp_trash_or_delete');

/**
 * Восстановление из корзины WP → снова отправить в Strapi.
 *
 * @param int $post_id
 */
function bazmet_on_wp_untrash($post_id) {
    if (bazmet_is_syncing()) {
        return;
    }
    $post = get_post($post_id);
    if (!$post || !in_array($post->post_type, ['bazmet_product', 'bazmet_service'], true)) {
        return;
    }
    bazmet_mark_wp_edited($post_id);
    if ($post->post_type === 'bazmet_product') {
        bazmet_push_product_to_strapi($post_id);
    } else {
        bazmet_push_service_to_strapi($post_id);
    }
}
add_action('untrash_post', 'bazmet_on_wp_untrash');

/**
 * Создаёт или обновляет изделие в Strapi из WP.
 * Важно: сначала ищем по meta _bazmet_strapi_document_id (надёжная связь),
 * потом по sku. Иначе при sku=null в Strapi WP каждый раз создавал БЫ НОВУЮ
 * запись, а старая в админке Strapi «не менялась».
 *
 * @param int $post_id
 * @return array{ok:bool,message:string}
 */
function bazmet_push_product_to_strapi($post_id) {
    $data = bazmet_wp_product_to_strapi_data($post_id);
    $sku  = $data['sku'];

    // 1) Уже связанный documentId из прошлых sync
    $meta_doc = get_post_meta($post_id, '_bazmet_strapi_document_id', true);
    $existing = $meta_doc ? bazmet_strapi_find_product_by_document_id($meta_doc) : null;

    // 2) Запасной ключ — sku (ярлык WP)
    if (!$existing && $sku !== '') {
        $existing = bazmet_strapi_find_product_by_sku($sku);
    }

    if ($existing && !empty($existing['documentId'])) {
        $doc_id = $existing['documentId'];
        $res = bazmet_strapi_put_product($doc_id, $data);
        if ($res['ok']) {
            update_post_meta($post_id, '_bazmet_strapi_document_id', $doc_id);
            // Помечаем: ближайший webhook от Strapi можно пропустить (анти-петля)
            set_transient('bazmet_ignore_strapi_webhook', 1, 15);
            return ['ok' => true, 'message' => "Изделие «{$sku}» обновлено в Strapi (documentId {$doc_id})"];
        }
        return ['ok' => false, 'message' => "Не удалось обновить «{$sku}»: " . $res['error']];
    }

    $res = bazmet_strapi_request('POST', '/api/products', ['data' => $data]);
    if (!$res['ok']) {
        return ['ok' => false, 'message' => "Не удалось создать «{$sku}»: " . $res['error']];
    }
    $created = bazmet_strapi_normalize_row($res['data']['data'] ?? []);
    if (!empty($created['documentId'])) {
        update_post_meta($post_id, '_bazmet_strapi_document_id', $created['documentId']);
        set_transient('bazmet_ignore_strapi_webhook', 1, 15);
    }
    return ['ok' => true, 'message' => "Изделие «{$sku}» создано в Strapi"];
}

/**
 * Создаёт или обновляет услугу в Strapi из WP.
 *
 * @param int $post_id
 * @return array{ok:bool,message:string}
 */
function bazmet_push_service_to_strapi($post_id) {
    $data  = bazmet_wp_service_to_strapi_data($post_id);
    $title = $data['title'];
    $doc_id = get_post_meta($post_id, '_bazmet_strapi_document_id', true);
    $existing = null;

    // Сначала documentId, потом title (как у изделий)
    if ($doc_id) {
        $res = bazmet_strapi_request(
            'PUT',
            '/api/service-items/' . rawurlencode($doc_id),
            ['data' => $data]
        );
        if ($res['ok']) {
            set_transient('bazmet_ignore_strapi_webhook', 1, 15);
            return ['ok' => true, 'message' => "Услуга «{$title}» обновлена в Strapi"];
        }
        // documentId устарел — ищем по title
        $existing = bazmet_strapi_find_service_by_title($title);
    } else {
        $existing = bazmet_strapi_find_service_by_title($title);
    }

    if ($existing && !empty($existing['documentId'])) {
        $res = bazmet_strapi_request(
            'PUT',
            '/api/service-items/' . rawurlencode($existing['documentId']),
            ['data' => $data]
        );
        if ($res['ok']) {
            update_post_meta($post_id, '_bazmet_strapi_document_id', $existing['documentId']);
            set_transient('bazmet_ignore_strapi_webhook', 1, 15);
            return ['ok' => true, 'message' => "Услуга «{$title}» обновлена в Strapi"];
        }
        return ['ok' => false, 'message' => "Не удалось обновить услугу: " . $res['error']];
    }

    $res = bazmet_strapi_request('POST', '/api/service-items', ['data' => $data]);
    if (!$res['ok']) {
        return ['ok' => false, 'message' => "Не удалось создать услугу: " . $res['error']];
    }
    $created = bazmet_strapi_normalize_row($res['data']['data'] ?? []);
    if (!empty($created['documentId'])) {
        update_post_meta($post_id, '_bazmet_strapi_document_id', $created['documentId']);
        set_transient('bazmet_ignore_strapi_webhook', 1, 15);
    }
    return ['ok' => true, 'message' => "Услуга «{$title}» создана в Strapi"];
}

/**
 * Включает флаг «сейчас идёт sync», чтобы save_post не ушёл в бесконечный цикл push.
 *
 * @param bool $on
 */
function bazmet_set_syncing($on) {
    $GLOBALS['bazmet_syncing'] = (bool) $on;
}

/**
 * @return bool
 */
function bazmet_is_syncing() {
    return !empty($GLOBALS['bazmet_syncing']);
}

/**
 * Создаёт/обновляет изделие в WordPress из данных Strapi (без повторного push).
 *
 * @param array<string,mixed> $item Нормализованная запись Strapi
 * @return array{ok:bool,message:string}
 */
function bazmet_pull_product_to_wp($item) {
    $raw_sku = trim((string) ($item['sku'] ?? ''));
    $doc_id  = trim((string) ($item['documentId'] ?? ''));
    // Всегда латинский безопасный slug (чинит %d0%… и пустой sku)
    $sku = bazmet_make_safe_product_slug($item['title'] ?? '', $doc_id, $raw_sku);

    // Сначала ищем по сохранённому documentId (даже если ярлык в WP другой)
    $post_id = 0;
    if ($doc_id !== '') {
        $by_meta = get_posts([
            'post_type'      => 'bazmet_product',
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'meta_key'       => '_bazmet_strapi_document_id',
            'meta_value'     => $doc_id,
        ]);
        if ($by_meta) {
            $post_id = (int) $by_meta[0]->ID;
        }
    }

    // Иначе — по ярлыку (post_name = sku), в т.ч. старый «битый» raw_sku
    if (!$post_id && bazmet_slug_is_safe($raw_sku)) {
        $existing = get_posts([
            'post_type'      => 'bazmet_product',
            'name'           => $raw_sku,
            'posts_per_page' => 1,
            'post_status'    => 'any',
        ]);
        $post_id = $existing ? (int) $existing[0]->ID : 0;
    }
    if (!$post_id && $raw_sku !== '' && $raw_sku !== $sku) {
        $existing = get_posts([
            'post_type'      => 'bazmet_product',
            'name'           => $raw_sku,
            'posts_per_page' => 1,
            'post_status'    => 'any',
        ]);
        $post_id = $existing ? (int) $existing[0]->ID : 0;
    }
    if (!$post_id) {
        $existing = get_posts([
            'post_type'      => 'bazmet_product',
            'name'           => $sku,
            'posts_per_page' => 1,
            'post_status'    => 'any',
        ]);
        $post_id = $existing ? (int) $existing[0]->ID : 0;
    }

    $fields = $item['fields'] ?? [];
    if (!$fields && !empty($item['selectFields']) && is_array($item['selectFields'])) {
        // selectFields (компоненты) → тот же формат, что _bazmet_fields
        $fields = [];
        foreach ($item['selectFields'] as $sf) {
            $fields[] = [
                'id'      => $sf['fieldId'] ?? ($sf['id'] ?? ''),
                'label'   => $sf['label'] ?? '',
                'options' => $sf['options'] ?? [],
            ];
        }
    }

    $postarr = [
        'post_type'    => 'bazmet_product',
        'post_status'  => 'publish',
        'post_title'   => $item['title'] ?? $sku,
        'post_name'    => $sku,
        'post_content' => $item['description'] ?? '',
        'post_excerpt' => mb_substr(wp_strip_all_tags($item['description'] ?? ''), 0, 180),
    ];

    bazmet_set_syncing(true);
    if ($post_id) {
        $postarr['ID'] = $post_id;
        $result = wp_update_post($postarr, true);
    } else {
        $result = wp_insert_post($postarr, true);
        $post_id = is_wp_error($result) ? 0 : (int) $result;
    }
    bazmet_set_syncing(false);

    if (is_wp_error($result) || !$post_id) {
        $msg = is_wp_error($result) ? $result->get_error_message() : 'ошибка записи';
        return ['ok' => false, 'message' => "WP «{$sku}»: {$msg}"];
    }

    update_post_meta($post_id, '_bazmet_fields', wp_json_encode($fields, JSON_UNESCAPED_UNICODE));
    if (!empty($item['documentId'])) {
        update_post_meta($post_id, '_bazmet_strapi_document_id', $item['documentId']);
    }

    // Дописываем/чиним sku в Strapi, если был пустой или percent-encoded
    if ($doc_id !== '' && !bazmet_slug_is_safe($raw_sku)) {
        bazmet_set_syncing(true);
        set_transient('bazmet_ignore_strapi_webhook', 1, 15);
        bazmet_strapi_put_product($doc_id, ['sku' => $sku]);
        bazmet_set_syncing(false);
    }

    // Категория Strapi → термин bazmet_type
    $cat = $item['category'] ?? null;
    $cat_slug = '';
    if (is_array($cat)) {
        $cat_slug = $cat['slug'] ?? '';
        if (!$cat_slug && isset($cat['data']['attributes']['slug'])) {
            $cat_slug = $cat['data']['attributes']['slug'];
        }
        if (!$cat_slug && isset($cat['data']['slug'])) {
            $cat_slug = $cat['data']['slug'];
        }
    }
    $rev = bazmet_strapi_slug_to_type();
    $wp_type = $rev[$cat_slug] ?? $cat_slug;
    if ($wp_type) {
        wp_set_object_terms($post_id, $wp_type, 'bazmet_type', false);
    }

    return ['ok' => true, 'message' => "Изделие «{$sku}» записано в WordPress"];
}

/**
 * Создаёт/обновляет услугу в WP из Strapi.
 *
 * @param array<string,mixed> $item
 * @return array{ok:bool,message:string}
 */
function bazmet_pull_service_to_wp($item) {
    $title = $item['title'] ?? '';
    if ($title === '') {
        return ['ok' => false, 'message' => 'У услуги Strapi нет title'];
    }

    $existing = get_posts([
        'post_type'      => 'bazmet_service',
        'title'          => $title,
        'posts_per_page' => 1,
        'post_status'    => 'any',
    ]);
    // WP_Query по title ненадёжен в старых WP — запасной поиск
    if (!$existing) {
        $all = get_posts([
            'post_type'      => 'bazmet_service',
            'posts_per_page' => 100,
            'post_status'    => 'any',
        ]);
        foreach ($all as $p) {
            if ($p->post_title === $title) {
                $existing = [$p];
                break;
            }
        }
    }
    $post_id = $existing ? (int) $existing[0]->ID : 0;

    $postarr = [
        'post_type'    => 'bazmet_service',
        'post_status'  => 'publish',
        'post_title'   => $title,
        'post_content' => $item['text'] ?? '',
    ];

    bazmet_set_syncing(true);
    if ($post_id) {
        $postarr['ID'] = $post_id;
        $result = wp_update_post($postarr, true);
    } else {
        $result = wp_insert_post($postarr, true);
        $post_id = is_wp_error($result) ? 0 : (int) $result;
    }
    bazmet_set_syncing(false);

    if (is_wp_error($result) || !$post_id) {
        $msg = is_wp_error($result) ? $result->get_error_message() : 'ошибка';
        return ['ok' => false, 'message' => "Услуга «{$title}»: {$msg}"];
    }
    if (!empty($item['documentId'])) {
        update_post_meta($post_id, '_bazmet_strapi_document_id', $item['documentId']);
    }
    return ['ok' => true, 'message' => "Услуга «{$title}» записана в WordPress"];
}

/**
 * Сравнивает одну пару записей по времени и решает: push, pull или skip.
 * Симметрично: WP новее → в Strapi; Strapi новее → в WP; равны → skip.
 *
 * @param int $wp_unix
 * @param int $strapi_unix
 * @return string 'push'|'pull'|'skip'
 */
function bazmet_lww_decide($wp_unix, $strapi_unix) {
    if ($wp_unix <= 0 && $strapi_unix <= 0) {
        return 'skip';
    }
    if ($wp_unix > $strapi_unix) {
        return 'push'; // WordPress новее → в Strapi (прямая связь)
    }
    if ($strapi_unix > $wp_unix) {
        return 'pull'; // Strapi новее → в WordPress (обратная связь)
    }
    return 'skip';
}

/**
 * Полная двусторонняя синхронизация каталога: изделия + услуги (last-write-wins).
 *
 * @return array{ok:bool,log:array<int,string>}
 */
function bazmet_run_full_sync() {
    $log = [];
    $log[] = 'Двусторонняя синхронизация WordPress ↔ Strapi (last-write-wins)';

    if (!bazmet_strapi_is_up()) {
        return [
            'ok'  => false,
            'log' => ['Strapi недоступен по адресу ' . bazmet_strapi_base_url() . ' — запустите CMS (работа № 9).'],
        ];
    }

    // ---------- ИЗДЕЛИЯ ----------
    $strapi_products = bazmet_strapi_fetch_all('products', 'populate=category');
    if (!$strapi_products['ok']) {
        return ['ok' => false, 'log' => ['Ошибка чтения products: ' . $strapi_products['error']]];
    }

    // Индексы Strapi: по documentId и по sku (sku у части записей бывает пустым)
    $by_doc = [];
    $by_sku = [];
    foreach ($strapi_products['items'] as $row) {
        $n = bazmet_strapi_normalize_row($row);
        if (!empty($n['documentId'])) {
            $by_doc[$n['documentId']] = $n;
        }
        if (!empty($n['sku'])) {
            $by_sku[$n['sku']] = $n;
        }
    }

    $wp_products = get_posts([
        'post_type'      => 'bazmet_product',
        'posts_per_page' => -1,
        'post_status'    => ['publish', 'draft', 'private'],
    ]);
    $seen_docs = [];
    $seen_sku  = [];

    foreach ($wp_products as $post) {
        $sku      = bazmet_product_sku($post->ID);
        $meta_doc = get_post_meta($post->ID, '_bazmet_strapi_document_id', true);
        $wp_ts    = bazmet_wp_modified_unix($post->ID);
        $seen_sku[$sku] = true;

        // Связь: сначала documentId из meta, иначе sku
        $s = null;
        if ($meta_doc && isset($by_doc[$meta_doc])) {
            $s = $by_doc[$meta_doc];
        } elseif ($sku !== '' && isset($by_sku[$sku])) {
            $s = $by_sku[$sku];
        }

        if (!$s) {
            // Была связь со Strapi, но записи там уже нет → удалили в Strapi → убираем и здесь
            if ($meta_doc) {
                bazmet_set_syncing(true);
                wp_delete_post($post->ID, true);
                bazmet_set_syncing(false);
                $log[] = '✕ изделие «' . $sku . '» удалено из WP (нет в Strapi, documentId ' . $meta_doc . ')';
            } else {
                // Локальная запись WP без связи — создаём в Strapi
                $r = bazmet_push_product_to_strapi($post->ID);
                $log[] = ($r['ok'] ? '→ ' : '⚠ ') . $r['message'] . ' (только WP)';
            }
            continue;
        }

        if (!empty($s['documentId'])) {
            $seen_docs[$s['documentId']] = true;
        }

        $s_ts = bazmet_time_to_unix($s['updatedAt'] ?? '');
        $decision = bazmet_lww_decide($wp_ts, $s_ts);

        if ($decision === 'push') {
            $r = bazmet_push_product_to_strapi($post->ID);
            $log[] = ($r['ok'] ? '→ ' : '⚠ ') . $r['message'] . ' [WP→Strapi]';
        } elseif ($decision === 'pull') {
            $r = bazmet_pull_product_to_wp($s);
            $log[] = ($r['ok'] ? '← ' : '⚠ ') . $r['message'] . ' [Strapi→WP]';
        } else {
            $log[] = '= «' . $sku . '» без изменений (одинаковое время)';
        }
    }

    // Изделия, которые есть только в Strapi (по documentId)
    foreach ($by_doc as $doc_id => $s) {
        if (isset($seen_docs[$doc_id])) {
            continue;
        }
        $r = bazmet_pull_product_to_wp($s);
        $log[] = ($r['ok'] ? '← ' : '⚠ ') . $r['message'] . ' (только Strapi)';
    }

    // ---------- УСЛУГИ ----------
    $strapi_services = bazmet_strapi_fetch_all('service-items');
    if (!$strapi_services['ok']) {
        $log[] = 'Ошибка чтения service-items: ' . $strapi_services['error'];
        return ['ok' => false, 'log' => $log];
    }

    $by_title = [];
    foreach ($strapi_services['items'] as $row) {
        $n = bazmet_strapi_normalize_row($row);
        if (!empty($n['title'])) {
            $by_title[$n['title']] = $n;
        }
    }

    $wp_services = get_posts([
        'post_type'      => 'bazmet_service',
        'posts_per_page' => -1,
        'post_status'    => ['publish', 'draft', 'private'],
    ]);
    $seen_titles = [];

    // Индекс услуг Strapi ещё и по documentId
    $svc_by_doc = [];
    foreach ($strapi_services['items'] as $row) {
        $n = bazmet_strapi_normalize_row($row);
        if (!empty($n['documentId'])) {
            $svc_by_doc[$n['documentId']] = $n;
        }
    }

    foreach ($wp_services as $post) {
        $title = $post->post_title;
        $seen_titles[$title] = true;
        $wp_ts = bazmet_wp_modified_unix($post->ID);
        $meta_doc = get_post_meta($post->ID, '_bazmet_strapi_document_id', true);

        $s = null;
        if ($meta_doc && isset($svc_by_doc[$meta_doc])) {
            $s = $svc_by_doc[$meta_doc];
        } elseif (isset($by_title[$title])) {
            $s = $by_title[$title];
        }

        if (!$s) {
            if ($meta_doc) {
                bazmet_set_syncing(true);
                wp_delete_post($post->ID, true);
                bazmet_set_syncing(false);
                $log[] = '✕ услуга «' . $title . '» удалена из WP (нет в Strapi)';
            } else {
                $r = bazmet_push_service_to_strapi($post->ID);
                $log[] = ($r['ok'] ? '→ ' : '⚠ ') . $r['message'] . ' (только WP)';
            }
            continue;
        }

        if (!empty($s['documentId'])) {
            // пометим title как увиденный (для блока «только Strapi»)
            $seen_titles[$s['title'] ?? $title] = true;
        }

        $s_ts = bazmet_time_to_unix($s['updatedAt'] ?? '');
        $decision = bazmet_lww_decide($wp_ts, $s_ts);

        if ($decision === 'push') {
            $r = bazmet_push_service_to_strapi($post->ID);
            $log[] = ($r['ok'] ? '→ ' : '⚠ ') . $r['message'] . ' [WP→Strapi]';
        } elseif ($decision === 'pull') {
            $r = bazmet_pull_service_to_wp($s);
            $log[] = ($r['ok'] ? '← ' : '⚠ ') . $r['message'] . ' [Strapi→WP]';
        } else {
            $log[] = '= услуга «' . $title . '» без изменений';
        }
    }

    foreach ($by_title as $title => $s) {
        if (isset($seen_titles[$title])) {
            continue;
        }
        $r = bazmet_pull_service_to_wp($s);
        $log[] = ($r['ok'] ? '← ' : '⚠ ') . $r['message'] . ' (только Strapi)';
    }

    update_option('bazmet_last_sync_log', $log);
    update_option('bazmet_last_sync_at', gmdate('c'));

    return ['ok' => true, 'log' => $log];
}

/**
 * Хук: после сохранения изделия/услуги в админке WP сразу пушим в Strapi.
 * Локальная правка = самое свежее изменение → всегда push (не pull).
 *
 * @param int     $post_id
 * @param WP_Post $post
 */
function bazmet_on_save_push_to_strapi($post_id, $post) {
    // Служебные случаи: автосохранение, ревизии, наш же sync
    if (bazmet_is_syncing()) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (wp_is_post_revision($post_id)) {
        return;
    }
    if (!in_array($post->post_type, ['bazmet_product', 'bazmet_service'], true)) {
        return;
    }
    if ($post->post_status === 'auto-draft') {
        return;
    }
    // Черновики в корзине не шлём
    if (!in_array($post->post_status, ['publish', 'draft', 'private'], true)) {
        return;
    }

    // Фиксируем время ручной правки ДО push — «часы» WP для LWW
    bazmet_mark_wp_edited($post_id);

    if ($post->post_type === 'bazmet_product') {
        $r = bazmet_push_product_to_strapi($post_id);
    } else {
        $r = bazmet_push_service_to_strapi($post_id);
    }

    // После успешного push снова ставим edited_at ≈ сейчас,
    // чтобы Strapi updatedAt (чуть позже) не откатил ту же правку обратно в WP
    if (!empty($r['ok'])) {
        bazmet_mark_wp_edited($post_id);
    }

    // Сообщение в админке после редиректа
    set_transient(
        'bazmet_sync_notice_' . get_current_user_id(),
        $r,
        60
    );
}
// Приоритет 99: после метабокса вариантов (priority 10), чтобы в Strapi ушли свежие select-поля
add_action('save_post', 'bazmet_on_save_push_to_strapi', 99, 2);

/**
 * Показ уведомления после сохранения («отправили в Strapi» / ошибка).
 */
function bazmet_admin_sync_notice() {
    $key = 'bazmet_sync_notice_' . get_current_user_id();
    $r = get_transient($key);
    if (!$r) {
        return;
    }
    delete_transient($key);
    $class = !empty($r['ok']) ? 'notice-success' : 'notice-error';
    echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>'
        . esc_html($r['message'] ?? '')
        . '</p></div>';
}
add_action('admin_notices', 'bazmet_admin_sync_notice');

/**
 * Пункт меню в админке: БазМет ↔ Strapi
 */
function bazmet_register_sync_menu() {
    add_menu_page(
        'БазМет ↔ Strapi',
        'БазМет Sync',
        'manage_options',
        'bazmet-strapi-sync',
        'bazmet_render_sync_page',
        'dashicons-update',
        58
    );
}
add_action('admin_menu', 'bazmet_register_sync_menu');

/**
 * Рисует страницу синхронизации в админке.
 */
function bazmet_render_sync_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $log = [];
    if (isset($_POST['bazmet_do_sync']) && check_admin_referer('bazmet_do_sync')) {
        $result = bazmet_run_full_sync();
        $log = $result['log'];
    } else {
        $log = get_option('bazmet_last_sync_log', []);
    }

    $up = bazmet_strapi_is_up();
    $last = get_option('bazmet_last_sync_at', '');

    echo '<div class="wrap">';
    echo '<h1>Синхронизация БазМет: WordPress ↔ Strapi</h1>';
    echo '<p><strong>Двусторонняя связь:</strong></p>';
    echo '<ul>';
    echo '<li><strong>WP → Strapi</strong> — сохранили изделие/услугу в WordPress → сразу уходит в Strapi</li>';
    echo '<li><strong>Strapi → WP</strong> — сохранили в Strapi → через ~2&nbsp;сек webhook обновляет WordPress</li>';
    echo '<li><strong>Удаление</strong> — корзина/удаление в WP → DELETE в Strapi; удаление в Strapi → после sync пропадает и в WP</li>';
    echo '</ul>';
    echo '<p>Правило при полном sync: <strong>новее по времени побеждает</strong> (last write wins). '
        . '→ отправка в Strapi, ← забор из Strapi в WordPress.</p>';
    echo '<p>Webhook: <code>POST /wp-json/bazmet/v1/sync</code> (секрет <code>bazmet-local-sync</code>). '
        . 'Оба сервера должны быть запущены: WP :8080 и Strapi :1337.</p>';
    echo '<p>Адрес Strapi: <code>' . esc_html(bazmet_strapi_base_url()) . '</code> — '
        . ($up ? '<span style="color:green">доступен</span>' : '<span style="color:#b00">не отвечает (запустите работу № 9)</span>')
        . '</p>';
    if ($last) {
        echo '<p>Последний полный sync: <code>' . esc_html($last) . '</code> (UTC)</p>';
    }

    echo '<form method="post">';
    wp_nonce_field('bazmet_do_sync');
    echo '<p><button type="submit" name="bazmet_do_sync" class="button button-primary" value="1">Синхронизировать сейчас</button></p>';
    echo '</form>';

    if ($log) {
        echo '<h2>Журнал</h2><ul style="font-family:monospace;max-width:900px">';
        foreach ((array) $log as $line) {
            echo '<li>' . esc_html($line) . '</li>';
        }
        echo '</ul>';
    }
    echo '</div>';
}

/**
 * Регистрация интервала WP-Cron (каждые 5 минут).
 *
 * @param array<string,array> $schedules
 * @return array<string,array>
 */
function bazmet_cron_intervals($schedules) {
    $schedules['bazmet_five_minutes'] = [
        'interval' => 300,
        'display'  => 'Каждые 5 минут (БазМет Sync)',
    ];
    return $schedules;
}
add_filter('cron_schedules', 'bazmet_cron_intervals');

/**
 * Планирует фоновый sync при загрузке темы (если ещё не запланирован).
 */
function bazmet_schedule_cron() {
    if (!wp_next_scheduled('bazmet_cron_sync')) {
        wp_schedule_event(time() + 60, 'bazmet_five_minutes', 'bazmet_cron_sync');
    }
}
add_action('init', 'bazmet_schedule_cron');

/**
 * Обработчик WP-Cron: тихий полный sync.
 */
function bazmet_cron_sync_handler() {
    bazmet_run_full_sync();
}
add_action('bazmet_cron_sync', 'bazmet_cron_sync_handler');

/**
 * Секрет для входящего webhook от Strapi (чтобы чужой сайт не дергал sync).
 *
 * @return string
 */
function bazmet_sync_secret() {
    if (defined('BAZMET_SYNC_SECRET') && BAZMET_SYNC_SECRET) {
        return (string) BAZMET_SYNC_SECRET;
    }
    return 'bazmet-local-sync';
}

/**
 * Регистрирует REST API: Strapi может вызвать sync без входа в админку WP.
 *
 * URL: POST http://127.0.0.1:8080/wp-json/bazmet/v1/sync
 * Заголовок: X-Bazmet-Sync-Secret: bazmet-local-sync
 */
function bazmet_register_sync_rest_route() {
    register_rest_route('bazmet/v1', '/sync', [
        'methods'             => 'POST',
        'callback'            => 'bazmet_rest_sync_from_strapi',
        'permission_callback' => 'bazmet_rest_sync_permission',
    ]);
}
add_action('rest_api_init', 'bazmet_register_sync_rest_route');

/**
 * Проверяет секрет в заголовке или в query (?secret=...).
 *
 * @param WP_REST_Request $request
 * @return bool
 */
function bazmet_rest_sync_permission($request) {
    $got = $request->get_header('x-bazmet-sync-secret');
    if (!$got) {
        $got = $request->get_param('secret');
    }
    return is_string($got) && hash_equals(bazmet_sync_secret(), $got);
}

/**
 * Callback REST: полный sync WordPress ↔ Strapi (last-write-wins).
 * Вызывается из Strapi после сохранения Product / Service Item.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function bazmet_rest_sync_from_strapi($request) {
    // Чтобы не запускать два sync подряд (кнопка + webhook)
    if (bazmet_is_syncing()) {
        return new WP_REST_Response([
            'ok'      => true,
            'skipped' => true,
            'message' => 'Sync уже выполняется',
        ], 200);
    }

    // Только что сами запушили WP→Strapi — не тянем обратно в ту же секунду
    if (get_transient('bazmet_ignore_strapi_webhook')) {
        delete_transient('bazmet_ignore_strapi_webhook');
        return new WP_REST_Response([
            'ok'      => true,
            'skipped' => true,
            'message' => 'Пропуск: это эхо после push из WordPress',
        ], 200);
    }

    // Полный двусторонний sync (LWW). source в теле — только для журнала.
    $result = bazmet_run_full_sync();
    $code   = !empty($result['ok']) ? 200 : 503;

    return new WP_REST_Response([
        'ok'      => !empty($result['ok']),
        'message' => !empty($result['ok'])
            ? 'Двусторонняя синхронизация завершена (новее по времени побеждает)'
            : 'Синхронизация с ошибкой',
        'log'     => $result['log'] ?? [],
    ], $code);
}
