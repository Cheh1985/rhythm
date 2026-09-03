(() => {
    'use strict';
    const locale = document.documentElement.lang === 'en' ? 'en' : 'ru';
    const en = {
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
