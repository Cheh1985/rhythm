(() => {
    'use strict';
    const locale = document.documentElement.lang === 'en' ? 'en' : 'ru';
    const en = {
        'Не удалось изменить настройку уведомлений.': 'Could not change the notification setting.',
        'Этот браузер не поддерживает Web Push. На iPhone установите PWA на экран «Домой».': 'This browser does not support Web Push. On iPhone, add the PWA to the Home Screen.',
        'Push-уведомления пока не настроены на сервере.': 'Push notifications are not configured on the server yet.',
        'Сначала установите доступное обновление приложения.': 'Install the available app update first.',
        'Уведомления запрещены системой. Разрешите их в настройках браузера или приложения.': 'Notifications are blocked by the system. Allow them in the browser or app settings.',
        'Нажмите «Включить уведомления», чтобы подтвердить системный запрос.': 'Press “Enable notifications” to confirm the system prompt.',
        'Уведомления об окончании отдыха включены на этом устройстве.': 'Rest completion notifications are enabled on this device.',
        'Уведомления на этом устройстве отключены.': 'Notifications are disabled on this device.',
        'Уведомления выключены. Разрешение будет запрошено только после нажатия кнопки.': 'Notifications are off. Permission will only be requested after you press the button.',
        'Включаем уведомления…': 'Enabling notifications…',
        'Подход синхронизируется. Повторите сохранение через несколько секунд.': 'The set is syncing. Try saving again in a few seconds.',
        'Обновите тренировку, сохранив очередь.': 'Reload the workout while preserving the queue.',
        'Упражнение заменено на «': 'The exercise was replaced with “',
        '». Повтор применит выбранную единицу к этому упражнению. Число в форме сохранится.': '”. Retry will apply the selected unit to this exercise. The number in the form will stay unchanged.',
        'Локальное действие сохранено. Свежая серверная версия загружена; выберите явно, как продолжить.': 'Your local action is saved. The latest server version is loaded; choose how to proceed.',
        'Проверяем синхронизацию…': 'Checking sync…',
        'Сохранено локально': 'Saved locally',
        'Все изменения синхронизированы': 'All changes are synced',
        'Есть несинхронизированные изменения': 'There are unsynced changes',
        'Ожидают синхронизации: ': 'Waiting to sync: ',
        'Онлайн': 'Online',
        'Офлайн': 'Offline',
        'Повторить': 'Retry',
        'Сохранение…': 'Saving…',
        'Сохранено': 'Saved',
        'Ошибка сохранения': 'Save failed',
        'Не удалось сохранить изменение.': 'Could not save the change.',
        'Не удалось загрузить тренировку.': 'Could not load the workout.',
        'Не удалось синхронизировать изменения.': 'Could not sync changes.',
        'Закрыть': 'Close',
        'Отдых полностью завершён': 'Rest is complete',
        'Тренировка изменилась в другой вкладке. Обновляем данные.': 'The workout changed in another tab. Refreshing data.',
        'Подход': 'Set',
        'Разминка': 'Warm-up',
        'Рабочий': 'Working',
        'Удалить': 'Delete',
        'Отменить': 'Cancel',
        'Сохранить': 'Save',
        'Причина замены': 'Replacement reason',
        'Причина пропуска': 'Skip reason',
        'Оборудование занято': 'Equipment busy',
        'Упражнение завершено': 'Exercise completed',
        'Пиктограмма упражнения': 'Exercise icon',
        'Развернуть упражнение': 'Expand exercise',
        'Свернуть упражнение': 'Collapse exercise',
        'цель RIR неизвестна': 'RIR target is unknown',
        'цель RIR': 'RIR target',
        'не указан': 'not specified',
        'Завершённых тренировок с этим упражнением пока нет.': 'There are no completed workouts with this exercise yet.',
        'Показана история': 'History shown',
        'тренировок': 'workouts',
        'рабочих подходов': 'working sets',
        'Показать тренировку за': 'Show workout for',
        'ниже цели': 'below target',
        'в цели': 'in target',
        'выше цели': 'above target',
        'сравнение недоступно': 'comparison unavailable',
        'подход': 'set',
        'Вес': 'Weight',
        'Параметры подхода': 'Set parameters',
        'Единица веса': 'Weight unit',
        'Уменьшить вес': 'Decrease weight',
        'Увеличить вес': 'Increase weight',
        'Уменьшить повторы': 'Decrease reps',
        'Увеличить повторы': 'Increase reps',
        'Уменьшить RIR': 'Decrease RIR',
        'Увеличить RIR': 'Increase RIR',
        'Ввести вес вручную': 'Enter weight manually',
        'Ввести повторы вручную': 'Enter reps manually',
        'Ввести RIR вручную': 'Enter RIR manually',
        'Не выбрано': 'Not selected',
        'Выберите значение': 'Select a value',
        'Карусель упражнений': 'Exercise carousel',
        'Навигация по упражнениям': 'Exercise navigation',
        'Предыдущее упражнение': 'Previous exercise',
        'Следующее упражнение': 'Next exercise',
        'Все упражнения': 'All exercises',
        'Карточки упражнений. Используйте стрелки влево и вправо для перехода.': 'Exercise cards. Use the left and right arrow keys to navigate.',
        'карточка': 'slide',
        'Прогресс': 'Progress',
        'Ожидает': 'Pending',
        'В работе': 'In progress',
        'Готово': 'Done',
        'Пропущено': 'Skipped',
        'Оборудование свободно': 'Equipment available',
        'из': 'of',
        'упражнений': 'exercises',
        'Синхронизировано': 'Synced',
        'Сохраняем локально и синхронизируем…': 'Saving locally and syncing…',
        'Ожидает синхронизации': 'Waiting to sync',
        'Офлайн · ожидает синхронизации': 'Offline · waiting to sync',
        'Ошибка · конфликт версий': 'Error · version conflict',
        'Ошибка синхронизации': 'Sync failed',
        'Замена ожидает синхронизации': 'Replacement is waiting to sync',
        'Дискомфорт записан локально': 'Discomfort was saved locally',
        'Завершение ожидает синхронизации': 'Workout completion is waiting to sync',
        'Пропустить упражнение': 'Skip exercise',
        'Оценить упражнение': 'Rate exercise',
        'Заменить упражнение': 'Replace exercise',
        'Записать дискомфорт': 'Log discomfort',
        'Изменить подход': 'Edit set',
        'Продолжить': 'Resume',
        'Пауза': 'Pause',
        'Закончить раньше': 'Finish early',
        'Таймер продолжит считать по времени окончания': 'The timer continues from its target end time',
        'Сохраняем готовность…': 'Saving readiness…',
        'Начать тренировку': 'Start workout',
        'кг': 'kg',
        'Программа активирована после ручного подтверждения.': 'Program activated after manual confirmation.',
        'Site tool выполнил изменение.': 'The Site tool completed the change.',
        'Site tool не выполнил изменение.': 'The Site tool did not complete the change.',
        'Activation отменена пользователем; данные не изменены.': 'Activation was cancelled by the user; no data changed.',
        'Сервер не опубликовал Site tools.': 'The server did not publish Site tools.',
        'Браузер не поддерживает document.modelContext; обычное приложение продолжает работать.': 'This browser does not support document.modelContext; the regular app continues to work.',
        'Регистрируем Site tools…': 'Registering Site tools…',
        'Не удалось зарегистрировать Site tools. Перезагрузите страницу или выключите feature flag.': 'Could not register Site tools. Reload the page or disable the feature flag.',
        'Готово: зарегистрировано ': 'Ready: registered ',
        ' Site tools для этой страницы.': ' Site tools for this page.',
        'Пользователь отменил activation. Программа не изменена.': 'The user cancelled activation. The program was not changed.',
        'Неизвестный Site tool.': 'Unknown Site tool.',
        'Не удалось выполнить Site tool.': 'Could not execute the Site tool.',
        'reason не должен превышать 1000 символов.': 'reason must not exceed 1,000 characters.',
    };
    const entries = Object.entries(en).sort((a, b) => b[0].length - a[0].length);
    const t = (text) => {
        if (locale !== 'en' || typeof text !== 'string') return text;
        let result = text;
        for (const [source, target] of entries) result = result.split(source).join(target);
        return result;
    };
    window.RhythmI18n = {locale, t};

    if (locale === 'en' && typeof MutationObserver !== 'undefined') {
        const translateNode = (node) => {
            if (node.nodeType === Node.TEXT_NODE && node.parentElement && !node.parentElement.closest('script,style,textarea,[contenteditable="true"]')) {
                const translated = t(node.nodeValue || '');
                if (translated !== node.nodeValue) node.nodeValue = translated;
                return;
            }
            if (node.nodeType !== Node.ELEMENT_NODE) return;

            const translateAttributes = (element) => {
                for (const attribute of ['aria-label', 'title', 'placeholder']) {
                    if (element.hasAttribute(attribute)) element.setAttribute(attribute, t(element.getAttribute(attribute) || ''));
                }
            };
            translateAttributes(node);
            node.querySelectorAll('*').forEach(translateAttributes);

            const walker = document.createTreeWalker(node, NodeFilter.SHOW_TEXT);
            let textNode;
            while ((textNode = walker.nextNode())) {
                if (textNode.parentElement && !textNode.parentElement.closest('script,style,textarea,[contenteditable="true"]')) {
                    const translated = t(textNode.nodeValue || '');
                    if (translated !== textNode.nodeValue) textNode.nodeValue = translated;
                }
            }
        };
        new MutationObserver((records) => records.forEach((record) => record.addedNodes.forEach(translateNode)))
            .observe(document.documentElement, {subtree: true, childList: true});
    }
})();
