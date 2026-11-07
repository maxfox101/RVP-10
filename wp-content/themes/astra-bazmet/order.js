/**
 * ============================================================================
 * order.js — заявка с карточки изделия (дочерняя тема Astra БазМет)
 * ============================================================================
 *
 * ЗАЧЕМ ЭТОТ ФАЙЛ
 * ---------------
 * На странице изделия пользователь выбирает в <select>:
 * материал, диаметр, длину, покрытие.
 * Этот скрипт при нажатии кнопки:
 *   1) проверяет, что все обязательные списки заполнены;
 *   2) собирает выбранные значения в query-строку URL;
 *   3) переводит браузер на страницу /zayavka/?product=…&material=…
 *
 * Там PHP-шорткод [bazmet_order] читает эти параметры из адреса
 * и показывает «Выбранную конфигурацию» + форму контактов.
 *
 * КАК ПОДКЛЮЧАЕТСЯ
 * ----------------
 * functions.php → wp_enqueue_script('astra-bazmet-order', …)
 * только на одиночном изделии и на странице zayavka.
 *
 * ЭТО ОБЫЧНЫЙ JAVASCRIPT В БРАУЗЕРЕ (не PHP и не Node).
 * Код обёрнут в (function () { … })(); — «сразу выполнить»,
 * чтобы переменные не попали в глобальную область window.
 */

(function () {
  /**
   * Находим форму с селектами размеров.
   * Если формы нет на странице (например открыли главную) — выходим.
   */
  var form = document.getElementById('bazmet-options-form');
  if (!form) {
    return;
  }

  /**
   * Базовый адрес страницы заявки берём из data-атрибута обёртки.
   * В PHP он задан как data-order-base="/zayavka/").
   */
  var wrap = form.closest('.bazmet-options');
  var base = wrap ? wrap.getAttribute('data-order-base') : '/zayavka/';

  /**
   * Обработчик отправки формы (клик по кнопке «Оставить заявку…»).
   * event.preventDefault() — не даём браузеру сделать обычный submit
   * (иначе страница просто перезагрузится без наших параметров).
   */
  form.addEventListener('submit', function (event) {
    event.preventDefault();

    /**
     * FormData читает все поля формы (hidden product + select'ы).
     */
    var data = new FormData(form);

    /**
     * Проверка: у каждого select[required] должно быть непустое value.
     * Если что-то не выбрано — фокус на первое пустое и alert.
     */
    var missing = [];
    form.querySelectorAll('select[required]').forEach(function (sel) {
      if (!sel.value) {
        missing.push(sel);
      }
    });
    if (missing.length) {
      missing[0].focus();
      alert('Выберите все варианты размера (материал, диаметр и т.д.).');
      return;
    }

    /**
     * Собираем query-параметры: ?product=Гайка…&material=Сталь&diameter=M10
     * URLSearchParams сам кодирует русские буквы для адреса.
     */
    var params = new URLSearchParams();
    data.forEach(function (value, key) {
      if (value) {
        params.set(key, value);
      }
    });

    /**
     * Переход на страницу заявки.
     * Если в base уже есть «?», клеим параметры через «&», иначе через «?».
     */
    var joiner = base.indexOf('?') >= 0 ? '&' : '?';
    window.location.href = base + joiner + params.toString();
  });
})();
