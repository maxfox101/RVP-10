# -*- coding: utf-8 -*-
"""Отчёт по лабораторной работе № 10 — темы и плагины WordPress (БазМет)."""
from docx import Document
from docx.shared import Pt, Cm
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml.ns import qn
from pathlib import Path

doc = Document()
for section in doc.sections:
    section.top_margin = Cm(2)
    section.bottom_margin = Cm(2)
    section.left_margin = Cm(3)
    section.right_margin = Cm(1.5)


def set_run_font(run, size=14, bold=False, name="Times New Roman"):
    run.font.name = name
    run._element.rPr.rFonts.set(qn("w:eastAsia"), name)
    run.font.size = Pt(size)
    run.bold = bold


def add_para(text, size=14, bold=False, align="justify", first_line=True):
    p = doc.add_paragraph()
    if align == "center":
        p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    elif align == "left":
        p.alignment = WD_ALIGN_PARAGRAPH.LEFT
    else:
        p.alignment = WD_ALIGN_PARAGRAPH.JUSTIFY
    pf = p.paragraph_format
    pf.space_after = Pt(6)
    pf.line_spacing = 1.5
    pf.first_line_indent = Cm(1.25) if (first_line and align == "justify") else Cm(0)
    run = p.add_run(text)
    set_run_font(run, size=size, bold=bold)
    return p


def add_h(text):
    p = doc.add_paragraph()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p.paragraph_format.space_before = Pt(12)
    p.paragraph_format.space_after = Pt(8)
    p.paragraph_format.line_spacing = 1.5
    run = p.add_run(text)
    set_run_font(run, bold=True)
    return p


for line in [
    "МИНИСТЕРСТВО НАУКИ И ВЫСШЕГО ОБРАЗОВАНИЯ РОССИЙСКОЙ ФЕДЕРАЦИИ",
    "Федеральное государственное автономное образовательное учреждение высшего образования",
    "«Южный федеральный университет» Инженерно-технологическая академия",
    "Институт компьютерных технологий и информационной безопасности",
]:
    add_para(line, align="center", first_line=False, size=12)

add_para("")
add_para("ОТЧЕТ", align="center", bold=True, first_line=False)
add_para("по лабораторной работе № 10", align="center", first_line=False)
add_para("Дисциплина: Разработка Web-приложений", align="center", first_line=False)
add_para(
    "Тема: Применение шаблонов и плагинов при разработке на CMS",
    align="center",
    first_line=False,
)
add_para("")
add_para("Выполнил: ст-т группы КТбо3-2 Михайлов М. И.", align="left", first_line=False)
add_para("Преподаватель: ассистент кафедры САиТ Шевцов А. П.", align="left", first_line=False)
doc.add_page_break()

add_h("1. Цель работы")
add_para(
    "Довести сайт на классической CMS до рабочего вида средствами темы и плагинов, "
    "выполнить хотя бы одну доработку вручную (кодом, а не только настройками), "
    "связать каталог WordPress с headless CMS Strapi (работа № 9) через REST API "
    "с правилом last-write-wins, сравнить результат с самостоятельным сайтом работ № 6–8 "
    "и ответить на вопросы темы."
)

add_h("2. Выбор CMS и установка")
add_para(
    "Для работы № 10 выбран WordPress — классическая CMS с экосистемой тем и плагинов. "
    "Сайт развёрнут в каталоге /Users/maksim/Documents/bazmet-wp. База данных — SQLite "
    "(плагин sqlite-database-integration), без отдельного MySQL. Локальный сервер: "
    "php -S 127.0.0.1:8080. Админка: http://127.0.0.1:8080/wp-admin/ "
    "(логин admin / BazMet123!). Язык сайта — русский (ru_RU)."
)

add_h("3. Тема и плагины (рабочий вид сайта)")
add_para(
    "Родительская тема: Astra — лёгкий каркас (шапка, меню, подвал). "
    "Дочерняя тема Astra БазМет (astra-bazmet) подключена поверх Astra, чтобы правки "
    "не потерялись при обновлении родителя. Важно активировать именно дочернюю тему, "
    "а не родительскую Astra."
)
add_para(
    "Плагины: Contact Form 7 — конструктор форм (типовое средство CMS для заявок); "
    "Custom Post Type UI — интерфейс для типов записей и таксономий; "
    "SQLite Database Integration — работа WordPress на SQLite без MySQL. "
    "Плагины Akismet и Hello Dolly не используются."
)
add_para(
    "Контент сайта: страницы Главная, Каталог, Услуги, О компании, Контакты; "
    "меню без отдельного пункта «Заявка» (заявка оформляется после выбора изделия или услуги); "
    "15 изделий каталога с фотографиями (как на оригинальном React-сайте); "
    "6 услуг завода; типы метизов (болты, гайки, винты, заклёпки, шайбы)."
)

add_h("4. Ручная доработка (не только настройки)")
add_para(
    "В дочерней теме wp-content/themes/astra-bazmet написан код:"
)
add_para(
    "1) functions.php — регистрация типов записей bazmet_product (Изделия) и "
    "bazmet_service (Услуги), таксономии bazmet_type; шорткоды [bazmet_hero], "
    "[bazmet_catalog], [bazmet_services], [bazmet_order]; вывод селектов вариантов "
    "на карточке изделия; русские подписи навигации и копирайта; подключение sync."
)
add_para(
    "2) order.js — сбор выбранных параметров (материал, диаметр, длина, покрытие) "
    "и переход на страницу заявки с query-параметрами. В файле добавлены подробные "
    "комментарии для разбора на защите."
)
add_para(
    "3) style.css — сетка каталога и услуг по 4 плашки в ряд, выравнивание карточек "
    "и кнопок, стили шапки и блока конфигурации заявки."
)
add_para(
    "4) inc/product-fields-metabox.php — в админке у изделия блок «Варианты выбора» "
    "(аналог selectFields в Strapi): можно менять доступные материалы, диаметры, длины "
    "и покрытия без правки PHP; данные хранятся в метаполе _bazmet_fields (JSON). "
    "При создании нового изделия стандартный набор селектов подставляется сразу."
)
add_para(
    "5) inc/strapi-sync.php — двусторонняя синхронизация каталога со Strapi по HTTP API "
    "(создание, обновление и удаление; см. раздел 5)."
)
add_para(
    "Для типов bazmet_product и bazmet_service отключён редактор блоков Gutenberg "
    "(use_block_editor_for_post_type = false): используется классическая форма "
    "«заголовок + текст + метабокс», чтобы экран «Добавить изделие/услугу» стабильно "
    "открывался. Вёрстка витрины задаётся шорткодами и CSS темы."
)

add_h("5. Синхронизация WordPress ↔ Strapi (last-write-wins)")
add_para(
    "Каталог в WordPress (работа № 10) и в Strapi (работа № 9) связан двусторонне. "
    "Связующее звено — REST API Strapi (http://127.0.0.1:1337/api/…). "
    "Кто изменил запись позже по времени, тот задаёт итоговое содержимое "
    "(last write wins)."
)
add_para(
    "Сравнение времени: в WordPress — meta _bazmet_edited_at (ставится только при "
    "ручном Save в админке WP; post_modified не используется, иначе после pull "
    "WordPress ложно казался «новее» и затирал правки Strapi); в Strapi — updatedAt. "
    "Ключ связи изделий — documentId Strapi (meta _bazmet_strapi_document_id), "
    "запасной — латинский sku / post_name. Услуги — по title и documentId."
)
add_para(
    "Прямая связь (WP → Strapi): сохранение изделия/услуги сразу делает PUT/POST "
    "в API. Обратная (Strapi → WP): после Save/Publish в Strapi lifecycle и "
    "Document Service middleware (~2 с) вызывают "
    "POST /wp-json/bazmet/v1/sync с заголовком X-Bazmet-Sync-Secret. "
    "Полный sync — меню «БазМет Sync» или WP-Cron раз в 5 минут. "
    "После push из WP ближайший webhook пропускается (анти-петля)."
)
add_para(
    "Удаление тоже двустороннее: корзина или удаление в WordPress сразу шлёт "
    "DELETE в Strapi; удаление в Strapi при следующем sync убирает запись из WP "
    "(если был documentId — иначе sync снова создал бы её в Strapi). "
    "Восстановление из корзины WP снова пушит запись в Strapi. "
    "В Public-роли Strapi разрешены create/update/delete для Product и Service."
)
add_para(
    "Журнал: «→» — WP→Strapi; «←» — Strapi→WP; «✕» — удаление; «=» — без изменений."
)

add_h("6. Сценарий заявки")
add_para(
    "Из каталога пользователь открывает изделие, выбирает материал / диаметр / длину / "
    "покрытие и нажимает «Оставить заявку на выбранный размер». "
    "Со страницы услуг — кнопка «Оставить заявку» с названием услуги. "
    "На /zayavka/ показывается выбранная конфигурация и форма контактов (имя, телефон, e-mail)."
)

add_h("7. Сравнение с работами № 6–8 и № 9")
add_para(
    "Работы № 6–8: фронтенд React (Vite), свой Express API, PostgreSQL "
    "(bazmet + bazmet_orders), картинки BYTEA, формы и каталог — полностью свой код. "
    "Плюсы: полный контроль, своя бизнес-логика, REST под конкретный UI. "
    "Минусы: дольше разработка, контент меняется через код/seed или отдельную админку."
)
add_para(
    "Работа № 9 (Strapi): headless CMS, контент и API отдельно от витрины. "
    "Работа № 10 (WordPress): готовая админка, темы и плагины; дополнительно каталог "
    "синхронизируется со Strapi, чтобы правки в одной CMS не терялись для другой."
)
add_para(
    "Вывод: для учебной витрины с уникальным UI и API удобнее стек 6–8; "
    "Strapi удобен как единый API-слой контента; WordPress быстрее даёт корпоративный "
    "сайт. Связка WP ↔ Strapi по времени изменения показывает типичный учебный приём "
    "интеграции двух CMS через API без общей базы данных."
)

add_h("8. Ответы на вопросы темы")
add_para(
    "Отличия в разработке различных видов CMS. "
    "Классические (WordPress, Drupal, Битрикс): тема/шаблоны PHP + плагины, контент и HTML "
    "часто связаны в одном стеке; разработка — хуки, CPT, шорткоды. "
    "Конструкторы (Tilda, Wix): почти без кода, ограничения по логике. "
    "Headless (Strapi, Contentful): только контент и API; фронтенд (React) отдельный — "
    "как в работе № 9. E-commerce CMS (Shopify, WooCommerce) добавляют корзину и оплату."
)
add_para(
    "Разработка на основе CMS с искусственным интеллектом. "
    "Современные CMS встраивают ИИ: генерация черновиков страниц и SEO-текстов, "
    "подсказки по структуре, авторазметка медиа, ассистенты в админке. "
    "ИИ ускоряет наполнение и черновую вёрстку, но схему данных, права доступа, "
    "безопасность и проверку фактов (ГОСТ, контакты) оставляет за разработчиком. "
    "Для БазМет ИИ мог бы помочь с описаниями изделий, а CPT, шорткоды, метабокс "
    "вариантов и модуль sync всё равно требуют кода."
)

add_h("9. Запуск")
add_para(
    "Strapi (работа № 9): cd /Users/maksim/Documents/database && npm run db:start; "
    "затем Node 22 и cd /Users/maksim/Documents/bazmet-cms && npm run develop. "
    "Админка Strapi: http://127.0.0.1:1337/admin ."
)
add_para(
    "WordPress (работа № 10): cd /Users/maksim/Documents/bazmet-wp && php -S 127.0.0.1:8080. "
    "Сайт: http://127.0.0.1:8080/ . Админка: /wp-admin/ . "
    "Синхронизация: меню «БазМет Sync». "
    "Ключевые файлы темы: functions.php, style.css, order.js, "
    "inc/product-fields-metabox.php, inc/strapi-sync.php."
)

add_h("10. Заключение")
add_para(
    "Сайт БазМет на WordPress доведён до рабочего вида темой Astra, плагинами "
    "CF7 / CPT UI / SQLite и ручным кодом дочерней темы (CPT, шорткоды, селекты размеров, "
    "метабокс вариантов, классическая форма создания, стили). Каталог двусторонне "
    "синхронизируется со Strapi: создание и правка по last-write-wins "
    "(_bazmet_edited_at ↔ updatedAt), удаление в обе стороны, webhook и меню Sync. "
    "Сравнение с работами 6–9 показало разницу между «своим» стеком, headless CMS "
    "и классической CMS на шаблонах. Работа № 10 выполнена."
)

# Один канонический файл в Documents (без копии в папке проекта)
out_docs = Path("/Users/maksim/Documents")
path = out_docs / "РВБ-10.docx"
doc.save(path)
print("saved", path)

old_doc = out_docs / "РВБ-10.doc"
if old_doc.exists():
    old_doc.unlink()
    print("removed", old_doc)
