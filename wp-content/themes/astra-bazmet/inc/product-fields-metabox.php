<?php
/**
 * Админка: метабокс «Варианты выбора» для CPT bazmet_product.
 *
 * Аналог selectFields (как в Strapi), но без плагина ACF — свой код.
 * Редактор задаёт материал / диаметр / длину / покрытие в админке;
 * на сайте изделие рисует <select> из meta `_bazmet_fields`
 * (см. bazmet_render_product_options в functions.php).
 *
 * Связка для защиты:
 *   админка (этот файл) → сохранить → post_meta JSON
 *   → страница изделия → селекты → заявка.
 */

if (!defined('ABSPATH')) {
    exit; // прямой заход в файл запрещён
}

/**
 * ---------------------------------------------------------------------------
 * Регистрация метабокса на экране редактирования изделия
 * ---------------------------------------------------------------------------
 */
add_action('add_meta_boxes', function () {
    add_meta_box(
        'bazmet_product_fields',
        'Варианты выбора (материал, размер, покрытие…)',
        'bazmet_render_product_fields_metabox', // функция отрисовки ниже
        'bazmet_product',                       // только для типа «Изделия»
        'normal',
        'high'
    );
});

/**
 * ---------------------------------------------------------------------------
 * Стандартный набор полей (кнопка «Подставить стандарт» в админке)
 * material / diameter / length / coating — как на витрине БазМет.
 * ---------------------------------------------------------------------------
 */
function bazmet_default_field_presets() {
    return [
        [
            'id' => 'material',
            'label' => 'Материал',
            'options' => [
                ['value' => 'Сталь 10', 'label' => 'Сталь 10'],
                ['value' => 'Сталь 20', 'label' => 'Сталь 20'],
                ['value' => 'Сталь 35', 'label' => 'Сталь 35'],
                ['value' => 'Сталь 40Х', 'label' => 'Сталь 40Х'],
                ['value' => 'Сталь 30ХГСА', 'label' => 'Сталь 30ХГСА'],
            ],
        ],
        [
            'id' => 'diameter',
            'label' => 'Диаметр резьбы',
            'options' => array_map(function ($v) {
                return ['value' => $v, 'label' => $v];
            }, ['M6', 'M8', 'M10', 'M12', 'M16', 'M20', 'M24', 'M30', 'M36', 'M42', 'M48']),
        ],
        [
            'id' => 'length',
            'label' => 'Длина',
            'options' => array_map(function ($v) {
                return ['value' => (string) $v, 'label' => (string) $v];
            }, [10, 16, 20, 25, 30, 40, 50, 60, 80, 100, 120, 150, 200, 250, 300]),
        ],
        [
            'id' => 'coating',
            'label' => 'Покрытие',
            'options' => [
                ['value' => 'Ц (цинковое)', 'label' => 'Ц (цинковое)'],
                ['value' => 'Хим.Окс', 'label' => 'Хим.Окс'],
                ['value' => 'без покрытия', 'label' => 'без покрытия'],
            ],
        ],
    ];
}

/**
 * Массив options → текст для textarea в админке (по строке на вариант).
 * Если value ≠ label — формат «значение | подпись».
 */
function bazmet_options_to_textarea($options) {
    $lines = [];
    foreach ((array) $options as $opt) {
        $value = isset($opt['value']) ? (string) $opt['value'] : '';
        $label = isset($opt['label']) ? (string) $opt['label'] : $value;
        if ($value === '') {
            continue;
        }
        $lines[] = ($label !== '' && $label !== $value) ? ($value . ' | ' . $label) : $value;
    }
    return implode("\n", $lines);
}

/**
 * Текст из textarea → массив options для JSON в post_meta.
 * Строка «M10» или «m10 | М10» → ['value' => …, 'label' => …].
 */
function bazmet_parse_options_textarea($text) {
    $options = [];
    foreach (preg_split("/\r\n|\n|\r/", (string) $text) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (strpos($line, '|') !== false) {
            [$value, $label] = array_map('trim', explode('|', $line, 2));
        } else {
            $value = $line;
            $label = $line;
        }
        if ($value === '') {
            continue;
        }
        $options[] = [
            'value' => $value,
            'label' => $label !== '' ? $label : $value,
        ];
    }
    return $options;
}

/**
 * ---------------------------------------------------------------------------
 * HTML метабокса: блоки «ID / Название / значения» + кнопки добавить/пресет
 * ---------------------------------------------------------------------------
 */
function bazmet_render_product_fields_metabox($post) {
    // Защита от CSRF при сохранении
    wp_nonce_field('bazmet_save_product_fields', 'bazmet_product_fields_nonce');

    // Уже сохранённые поля (JSON в meta `_bazmet_fields`)
    $raw = get_post_meta($post->ID, '_bazmet_fields', true);
    $fields = json_decode($raw ?: '[]', true);
    if (!is_array($fields)) {
        $fields = [];
    }
    // Новое изделие (ещё нет meta) — сразу открываем стандартные селекты,
    // чтобы форма «Варианты выбора» была заполнена при создании.
    if (!$fields) {
        $fields = bazmet_default_field_presets();
    }
    ?>
    <p style="margin-top:0">
        Как <strong>selectFields</strong> в Strapi: каждый блок — один выпадающий список на сайте.
        В списке значений — <em>по одному варианту на строку</em> (можно <code>значение | подпись</code>).
    </p>
    <p>
        <button type="button" class="button" id="bazmet-add-field">+ Добавить пункт</button>
        <button type="button" class="button" id="bazmet-load-presets">Подставить стандарт (материал / диаметр / длина / покрытие)</button>
    </p>
    <div id="bazmet-fields-wrap">
        <?php foreach ($fields as $i => $field) :
            $fid = esc_attr($field['id'] ?? '');
            $flabel = esc_attr($field['label'] ?? '');
            $fopts = esc_textarea(bazmet_options_to_textarea($field['options'] ?? []));
            ?>
            <div class="bazmet-admin-field" style="border:1px solid #c3c4c7;border-radius:6px;padding:12px;margin:0 0 12px;background:#fff">
                <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
                    <p style="margin:0;flex:1;min-width:140px">
                        <label>ID поля<br>
                            <input type="text" name="bazmet_field_id[]" value="<?php echo $fid; ?>" placeholder="material" class="widefat" />
                        </label>
                    </p>
                    <p style="margin:0;flex:2;min-width:180px">
                        <label>Название (как на сайте)<br>
                            <input type="text" name="bazmet_field_label[]" value="<?php echo $flabel; ?>" placeholder="Материал" class="widefat" />
                        </label>
                    </p>
                    <p style="margin:0">
                        <button type="button" class="button link-delete bazmet-remove-field">Удалить</button>
                    </p>
                </div>
                <p style="margin:10px 0 0">
                    <label>Доступные значения (по одному в строке)<br>
                        <textarea name="bazmet_field_options[]" rows="5" class="widefat" placeholder="Сталь 10&#10;Сталь 20&#10;M10&#10;Ц (цинковое)"><?php echo $fopts; ?></textarea>
                    </label>
                </p>
            </div>
        <?php endforeach; ?>
    </div>
    <?php /* Шаблон нового блока — клонируется кнопкой «+ Добавить пункт» */ ?>
    <template id="bazmet-field-template">
        <div class="bazmet-admin-field" style="border:1px solid #c3c4c7;border-radius:6px;padding:12px;margin:0 0 12px;background:#fff">
            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
                <p style="margin:0;flex:1;min-width:140px">
                    <label>ID поля<br>
                        <input type="text" name="bazmet_field_id[]" value="" placeholder="material" class="widefat" />
                    </label>
                </p>
                <p style="margin:0;flex:2;min-width:180px">
                    <label>Название (как на сайте)<br>
                        <input type="text" name="bazmet_field_label[]" value="" placeholder="Материал" class="widefat" />
                    </label>
                </p>
                <p style="margin:0">
                    <button type="button" class="button link-delete bazmet-remove-field">Удалить</button>
                </p>
            </div>
            <p style="margin:10px 0 0">
                <label>Доступные значения (по одному в строке)<br>
                    <textarea name="bazmet_field_options[]" rows="5" class="widefat"></textarea>
                </label>
            </p>
        </div>
    </template>
    <script>
    (function () {
        var wrap = document.getElementById('bazmet-fields-wrap');
        var tpl = document.getElementById('bazmet-field-template');
        // Добавить пустой блок поля
        document.getElementById('bazmet-add-field').addEventListener('click', function () {
            wrap.appendChild(tpl.content.cloneNode(true));
        });
        // Удалить блок (последний — только очистить, чтобы форма не осталась пустой)
        wrap.addEventListener('click', function (e) {
            if (e.target.classList.contains('bazmet-remove-field')) {
                var card = e.target.closest('.bazmet-admin-field');
                if (card && wrap.querySelectorAll('.bazmet-admin-field').length > 1) {
                    card.remove();
                } else if (card) {
                    card.querySelectorAll('input, textarea').forEach(function (el) { el.value = ''; });
                }
            }
        });
        // Подставить стандартный набор material / diameter / length / coating
        document.getElementById('bazmet-load-presets').addEventListener('click', function () {
            if (!confirm('Заменить текущие пункты стандартным набором (материал, диаметр, длина, покрытие)?')) return;
            var presets = <?php echo wp_json_encode(bazmet_default_field_presets(), JSON_UNESCAPED_UNICODE); ?>;
            wrap.innerHTML = '';
            presets.forEach(function (p) {
                var node = tpl.content.cloneNode(true);
                node.querySelector('input[name="bazmet_field_id[]"]').value = p.id;
                node.querySelector('input[name="bazmet_field_label[]"]').value = p.label;
                node.querySelector('textarea[name="bazmet_field_options[]"]').value = (p.options || []).map(function (o) {
                    return o.value;
                }).join('\n');
                wrap.appendChild(node);
            });
        });
    })();
    </script>
    <?php
}

/**
 * ---------------------------------------------------------------------------
 * Сохранение метабокса → post_meta `_bazmet_fields` (JSON)
 * Срабатывает при «Обновить» / «Опубликовать» изделия.
 * На сайте эти данные читает bazmet_render_product_options().
 * ---------------------------------------------------------------------------
 */
add_action('save_post_bazmet_product', function ($post_id) {
    // Проверки: nonce, не автосохранение, права редактора
    if (!isset($_POST['bazmet_product_fields_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bazmet_product_fields_nonce'])), 'bazmet_save_product_fields')) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Массивы из формы: id[], label[], options[] — по одному на блок
    $ids = isset($_POST['bazmet_field_id']) ? (array) $_POST['bazmet_field_id'] : [];
    $labels = isset($_POST['bazmet_field_label']) ? (array) $_POST['bazmet_field_label'] : [];
    $options = isset($_POST['bazmet_field_options']) ? (array) $_POST['bazmet_field_options'] : [];

    $fields = [];
    $count = max(count($ids), count($labels), count($options));
    for ($i = 0; $i < $count; $i++) {
        $id = sanitize_key(wp_unslash($ids[$i] ?? ''));
        $label = sanitize_text_field(wp_unslash($labels[$i] ?? ''));
        $opts = bazmet_parse_options_textarea(wp_unslash($options[$i] ?? ''));
        // Пустой блок пропускаем
        if ($id === '' && $label === '' && !$opts) {
            continue;
        }
        if ($id === '') {
            $id = sanitize_key($label) ?: ('field_' . ($i + 1));
        }
        if ($label === '') {
            $label = $id;
        }
        $fields[] = [
            'id' => $id,
            'label' => $label,
            'options' => $opts,
        ];
    }

    // Пишем JSON в meta — его читает витрина изделия
    update_post_meta($post_id, '_bazmet_fields', wp_json_encode($fields, JSON_UNESCAPED_UNICODE));
});
